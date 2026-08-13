<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;
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
 */
final readonly class XpRates
{
    /** @var array<string, int> valeur de discipline → plafond d'XP quotidien */
    private array $dailyCap;

    /** @var array<string, int> valeur de discipline → XP par kilomètre, absente si la discipline n'en accorde pas */
    private array $perKilometre;

    /** @var array<string, int> valeur de discipline → XP par 100 m de dénivelé positif */
    private array $perHundredMetresOfElevation;

    /**
     * @param int                                                                                              $baseXpPerHour 60 : une minute, un point
     * @param list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}> $disciplines
     */
    public function __construct(
        private int $baseXpPerHour,
        array $disciplines,
    ) {
        if ($baseXpPerHour < 1) {
            throw new InvalidArgumentException('Une heure de pratique doit valoir au moins 1 XP.');
        }

        $dailyCap = [];
        $perKilometre = [];
        $perHundredMetresOfElevation = [];

        foreach ($disciplines as $rate) {
            $discipline = Discipline::tryFrom($rate['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue au barème d\'XP : "%s".', $rate['discipline']));

            if (isset($dailyCap[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Discipline en double au barème d\'XP : "%s".', $discipline->value));
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
        // le trou, pas nous. On préfère ne pas démarrer.
        foreach (Discipline::cases() as $discipline) {
            if (!isset($dailyCap[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Aucun barème d\'XP pour la discipline "%s".', $discipline->value));
            }
        }

        $this->dailyCap = $dailyCap;
        $this->perKilometre = $perKilometre;
        $this->perHundredMetresOfElevation = $perHundredMetresOfElevation;
    }

    public function baseXpPerHour(): int
    {
        return $this->baseXpPerHour;
    }

    /** Le maximum d'XP que cette discipline peut accorder sur une journée du joueur. */
    public function dailyCapOf(Discipline $discipline): int
    {
        return $this->dailyCap[$discipline->value];
    }

    /**
     * Le socle d'une séance, au prorata de sa durée retenue. Il ne dépend pas de la
     * discipline : une minute vaut une minute.
     *
     * @throws InvalidArgumentException une durée négative n'est pas une séance courte, c'est un bug d'appelant
     */
    public function baseFor(int $durationSeconds): int
    {
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
        if (null === $distanceMeters || !isset($this->perKilometre[$discipline->value])) {
            return 0;
        }

        return intdiv(max(0, $distanceMeters) * $this->perKilometre[$discipline->value], 1000);
    }

    /** Le pendant exact pour le dénivelé positif, par tranche de 100 mètres. */
    public function elevationBonusOf(Discipline $discipline, ?int $elevationGainMeters): int
    {
        if (null === $elevationGainMeters || !isset($this->perHundredMetresOfElevation[$discipline->value])) {
            return 0;
        }

        return intdiv(max(0, $elevationGainMeters) * $this->perHundredMetresOfElevation[$discipline->value], 100);
    }
}
