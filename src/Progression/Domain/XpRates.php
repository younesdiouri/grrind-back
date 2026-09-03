<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\RuntimeRuleset;
use InvalidArgumentException;

/**
 * Le barème de base : une minute vaut un point, plus les kilomètres, plus le dénivelé.
 *
 * Objet typé et immuable, construit une fois à la compilation du conteneur depuis
 * `config/game/v1/xp.yaml`. C'est lui qui porte les règles de cohérence du barème, et
 * `XpSection` les lui fait dire plutôt que de les réécrire.
 *
 * **Le socle ne dépend plus de la discipline.** Un taux horaire par sport était une
 * calibration inventée avant d'avoir un seul joueur ; le socle commun se défend en une
 * phrase, et ce qui distingue les disciplines est désormais mesuré — la distance, le
 * dénivelé — ou assumé comme absent.
 *
 * **Arithmétique entière de bout en bout.** Les trois divisions tronquent vers le bas : une
 * séance ne rapporte jamais plus que ce que le barème annonce, et aucun arrondi flottant ne
 * se glisse dans une valeur qui finira au ledger.
 *
 * **Une discipline peut ne pas créditer du tout (#167).** `WALKING` porte `credits_xp:
 * false` au lieu d'un plafond : la marche n'alimente que Vitality, jamais l'XP, et cette
 * marque le dit explicitement plutôt qu'un `daily_cap_xp: 0` qui traverserait tout le
 * calcul pour se faire écrêter à l'arrivée — le breakdown afficherait « 90 XP, écrêtés à
 * 0 », la punition qu'on cherche justement à éviter. `credits()` est la question que pose
 * `LedgerSessionRewards` **avant** d'appeler quoi que ce soit d'autre sur cette classe :
 * une discipline qui ne crédite pas n'atteint jamais `baseFor()`, `dailyCapOf()` ni les
 * bonus de terrain, et ne consomme donc ni les rendements décroissants ni le plafond
 * quotidien d'aucune autre discipline pratiquée le même jour.
 */
final class XpRates
{
    use RuntimeRuleset;
    /** @var array<string, int> valeur de discipline → plafond d'XP quotidien, uniquement les disciplines qui créditent */
    private array $dailyCap;

    /** @var array<string, int> valeur de discipline → XP par kilomètre, absente si la discipline n'en accorde pas */
    private array $perKilometre;

    /** @var array<string, int> valeur de discipline → XP par 100 m de dénivelé positif */
    private array $perHundredMetresOfElevation;

    /** @var array<string, true> valeur de discipline → présente si elle ne crédite pas d'XP */
    private array $nonCrediting;

    /**
     * @param int                                                                                                                  $baseXpPerHour 60 : une minute, un point
     * @param list<array{discipline: string, daily_cap_xp?: int, xp_per_km?: int, xp_per_100m_elevation?: int, credits_xp?: bool}> $disciplines
     */
    public function __construct(
        private int $baseXpPerHour,
        array $disciplines,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        if ($baseXpPerHour < 1) {
            throw new InvalidArgumentException('Une heure de pratique doit valoir au moins 1 XP.');
        }

        $dailyCap = [];
        $perKilometre = [];
        $perHundredMetresOfElevation = [];
        $nonCrediting = [];

        foreach ($disciplines as $rate) {
            $discipline = Discipline::tryFrom($rate['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue au barème d\'XP : "%s".', $rate['discipline']));

            if (isset($dailyCap[$discipline->value]) || isset($nonCrediting[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Discipline en double au barème d\'XP : "%s".', $discipline->value));
            }

            // `credits_xp: true` ne dirait rien de plus que son absence : la seule valeur
            // qui a un sens à écrire est `false`, donc c'est la seule qu'on accepte.
            if (\array_key_exists('credits_xp', $rate) && true === $rate['credits_xp']) {
                throw new InvalidArgumentException(\sprintf('"%s" porte "credits_xp: true", qui ne dit rien de plus que son absence — retirer la clé.', $discipline->value));
            }

            // Rejeté juste au-dessus si elle vaut `true` : n'atteint ce point qu'absente
            // ou explicitement `false`.
            if (false === ($rate['credits_xp'] ?? true)) {
                // Ni plafond ni bonus : une discipline qui ne crédite pas n'a rien à
                // écrêter, et un `xp_per_km` resterait une promesse que personne ne tient.
                if (isset($rate['daily_cap_xp']) || isset($rate['xp_per_km']) || isset($rate['xp_per_100m_elevation'])) {
                    throw new InvalidArgumentException(\sprintf('"%s" porte à la fois "credits_xp: false" et un plafond ou un bonus — les deux ne peuvent pas coexister.', $discipline->value));
                }

                $nonCrediting[$discipline->value] = true;

                continue;
            }

            if (!isset($rate['daily_cap_xp'])) {
                throw new InvalidArgumentException(\sprintf('"%s" ne porte ni plafond quotidien ni "credits_xp: false" — l\'un des deux est obligatoire.', $discipline->value));
            }

            // Un plafond sous ce qu'une seule heure de socle rapporte ferait du garde-fou le
            // limiteur principal, à la place des rendements décroissants — et le joueur
            // buterait dessus tous les jours sans comprendre pourquoi.
            if ($rate['daily_cap_xp'] < $baseXpPerHour) {
                throw new InvalidArgumentException(\sprintf('Le plafond quotidien de "%s" (%d) est sous ce qu\'une heure de socle rapporte (%d).', $discipline->value, $rate['daily_cap_xp'], $baseXpPerHour));
            }

            $dailyCap[$discipline->value] = $rate['daily_cap_xp'];

            // Absente vaut « pas de bonus », et surtout pas zéro : une clé posée à zéro
            // afficherait « +0 XP pour tes 0 km » à un joueur qui vient de soulever de la
            // fonte. Le schéma refuse le zéro pour que la seule façon de ne pas accorder de
            // bonus soit de ne pas en déclarer.
            if (isset($rate['xp_per_km'])) {
                $perKilometre[$discipline->value] = $rate['xp_per_km'];
            }

            if (isset($rate['xp_per_100m_elevation'])) {
                $perHundredMetresOfElevation[$discipline->value] = $rate['xp_per_100m_elevation'];
            }
        }

        // Une discipline sans barème rapporterait zéro en silence — un joueur découvrirait
        // le trou, pas nous. On préfère ne pas démarrer. Créditer ou pas, chaque discipline
        // doit trancher explicitement.
        foreach (Discipline::cases() as $discipline) {
            if (!isset($dailyCap[$discipline->value]) && !isset($nonCrediting[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Aucun barème d\'XP pour la discipline "%s".', $discipline->value));
            }
        }

        $this->dailyCap = $dailyCap;
        $this->perKilometre = $perKilometre;
        $this->perHundredMetresOfElevation = $perHundredMetresOfElevation;
        $this->nonCrediting = $nonCrediting;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    public function baseXpPerHour(): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->baseXpPerHour();
        }

        return $this->baseXpPerHour;
    }

    /**
     * La question à poser avant tout le reste. `false` pour une discipline comme
     * `WALKING` : `LedgerSessionRewards` s'arrête là, avant `XpCalculator`, avant la
     * charge du jour — rien de tout ça n'a de sens pour une discipline qui n'accorde rien.
     */
    public function credits(Discipline $discipline): bool
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->credits($discipline);
        }

        return !isset($this->nonCrediting[$discipline->value]);
    }

    /**
     * Le maximum d'XP que cette discipline peut accorder sur une journée du joueur.
     *
     * @throws InvalidArgumentException appelé pour une discipline qui ne crédite pas — un
     *                                  bug d'appelant, `credits()` doit être vérifié avant
     */
    public function dailyCapOf(Discipline $discipline): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->dailyCapOf($discipline);
        }

        return $this->dailyCap[$discipline->value]
            ?? throw new InvalidArgumentException(\sprintf('"%s" ne crédite pas d\'XP, elle n\'a pas de plafond quotidien — vérifier credits() avant d\'appeler dailyCapOf().', $discipline->value));
    }

    /**
     * Le socle d'une séance, au prorata de sa durée retenue. Il ne dépend pas de la
     * discipline : une minute vaut une minute.
     *
     * @throws InvalidArgumentException une durée négative n'est pas une séance courte, c'est un bug d'appelant
     */
    public function baseFor(int $durationSeconds): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->baseFor($durationSeconds);
        }

        if ($durationSeconds < 0) {
            throw new InvalidArgumentException(\sprintf('Une séance ne dure pas %d secondes.', $durationSeconds));
        }

        return intdiv($durationSeconds * $this->baseXpPerHour, 3600);
    }

    /**
     * Ce que la distance ajoute, ou zéro — ce qui couvre les deux cas où il n'y a rien à
     * ajouter : la discipline n'accorde pas de bonus de distance, ou la montre n'a rien
     * mesuré. L'appelant ne produit pas de ligne pour un zéro, donc la distinction ne se
     * perd nulle part.
     */
    public function distanceBonusOf(Discipline $discipline, ?int $distanceMeters): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->distanceBonusOf($discipline, $distanceMeters);
        }

        if (null === $distanceMeters || !isset($this->perKilometre[$discipline->value])) {
            return 0;
        }

        return intdiv(max(0, $distanceMeters) * $this->perKilometre[$discipline->value], 1000);
    }

    /** Le pendant exact pour le dénivelé positif, par tranche de 100 mètres. */
    public function elevationBonusOf(Discipline $discipline, ?int $elevationGainMeters): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->elevationBonusOf($discipline, $elevationGainMeters);
        }

        if (null === $elevationGainMeters || !isset($this->perHundredMetresOfElevation[$discipline->value])) {
            return 0;
        }

        return intdiv(max(0, $elevationGainMeters) * $this->perHundredMetresOfElevation[$discipline->value], 100);
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{base_xp_per_hour: int} $xp */
        $xp = $snapshot['xp'];
        /** @var list<array{discipline: string, credits_xp: bool, daily_cap_xp: ?int, xp_per_km: ?int, xp_per_100m_elevation: ?int}> $disciplines */
        $disciplines = $snapshot['disciplines'];
        $rates = array_map(static function (array $discipline): array {
            $rate = ['discipline' => $discipline['discipline']];
            if (!$discipline['credits_xp']) {
                $rate['credits_xp'] = false;
            }
            foreach (['daily_cap_xp', 'xp_per_km', 'xp_per_100m_elevation'] as $key) {
                if (null !== $discipline[$key]) {
                    $rate[$key] = $discipline[$key];
                }
            }

            return $rate;
        }, $disciplines);

        return new self($xp['base_xp_per_hour'], $rates, $rulesets);
    }
}
