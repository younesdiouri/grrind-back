<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
use InvalidArgumentException;

/**
 * Les disciplines qui rapportent de l'XP, lues une seule fois depuis la liste brute de
 * snapshot publié des disciplines créditées.
 *
 * **La question « qui crédite ? » se posait déjà à deux endroits** — `XpRates`, qui la
 * tranche pour le calcul, et {@see AttributeSplit}, qui s'en sert pour refuser une ligne de
 * répartition morte. Le #191 en apporte un troisième, dans un autre module : une Risāla ne
 * peut pas défier la guilde sur `WALKING`, puisque +150 % de zéro fait zéro et que le
 * joueur y verrait une promesse non tenue.
 *
 * Trois lecteurs de la même liste, c'est le moment où l'on écrit la lecture une fois. Ici
 * plutôt que dans un module : la liste vit dans `xp.yaml`, que `Progression` possède, mais
 * la réponse intéresse `Community` — et Deptrac interdit la flèche. C'est exactement ce que
 * `Shared/Domain/Activity` existe pour porter, au même titre que {@see Discipline}.
 *
 * `XpRates` continue de parcourir la liste pour son compte : il en tire aussi les taux de
 * distance et de dénivelé, donc il la lit de toute façon. Ce n'est pas cette duplication-là
 * qu'on ferme.
 */
final class CreditingDisciplines
{
    use RuntimeRuleset;
    /** @var array<string, true> valeur de discipline → présence, pour une réponse en O(1) */
    private array $crediting;

    /**
     * @param list<array{discipline: string, credits_xp?: bool}> $disciplines la liste brute de `xp.yaml` — seule `credits_xp` compte ici
     */
    public function __construct(array $disciplines, ?GameRulesets $rulesets = null)
    {
        $this->useRuntimeRulesets($rulesets);
        $crediting = [];

        foreach ($disciplines as $rate) {
            $discipline = Discipline::tryFrom($rate['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue dans la liste des disciplines de "xp.yaml" : "%s".', $rate['discipline']));

            // Absente vaut « crédite » : c'est le cas de toutes les disciplines sauf une,
            // et un défaut inverse obligerait à écrire `credits_xp: true` onze fois.
            if (false !== ($rate['credits_xp'] ?? true)) {
                $crediting[$discipline->value] = true;
            }
        }

        $this->crediting = $crediting;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    public function credits(Discipline $discipline): bool
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->credits($discipline);
        }

        return isset($this->crediting[$discipline->value]);
    }

    /**
     * Les disciplines créditantes **dans l'ordre de déclaration de {@see Discipline}**, et
     * non dans celui du YAML : c'est une liste que le client affiche (le choix d'une Risāla,
     * #193), et un ordre qui dépendrait de la mise en forme d'un fichier de configuration
     * changerait sous les doigts du joueur au premier rééquilibrage.
     *
     * @return list<Discipline>
     */
    public function all(): array
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->all();
        }

        return array_values(array_filter(Discipline::cases(), $this->credits(...)));
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var list<array{discipline: string, credits_xp: bool}> $disciplines */
        $disciplines = $snapshot['disciplines'];
        /** @var list<array{discipline: string, credits_xp?: bool}> $rates */
        $rates = [];
        foreach ($disciplines as $discipline) {
            $rate = ['discipline' => $discipline['discipline']];
            if (!$discipline['credits_xp']) {
                $rate['credits_xp'] = false;
            }
            $rates[] = $rate;
        }

        return new self($rates, $rulesets);
    }
}
