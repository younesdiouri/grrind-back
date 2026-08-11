<?php

declare(strict_types=1);

namespace App\Shared\Domain\Modifier;

use App\Shared\Domain\Activity\Discipline;

/**
 * Un effet actif sur un joueur : de quel type, combien, sur quoi, et grâce à qui.
 *
 * **La valeur est un entier, et son unité dépend du type.** Pour un `XP_MULTIPLIER`,
 * c'est un pourcentage entier ajouté au socle : `20` vaut « +20 % du socle ». Pas de
 * flottant, jamais — un multiplicateur en `float` se compose en accumulant des erreurs
 * d'arrondi qui finissent écrites au ledger, où elles ne se corrigent plus.
 *
 * **La portée est une discipline, ou rien.** `null` vaut « partout » ; une valeur vaut
 * « uniquement pour cette discipline ». C'est ce qui permet des bottes de course qui ne
 * servent à rien en natation sans que le calcul ait à connaître les objets.
 *
 * Value object pur, dans `Shared` : le resolver (#18) en produira, `XpCalculator` et
 * `LootRoller` en consommeront, et aucun de ces modules ne connaît les autres.
 */
final readonly class Modifier
{
    public function __construct(
        public ModifierType $type,
        public int $value,
        public ModifierSource $source,
        /** `null` = global. Une discipline = ne s'applique qu'à elle. */
        public ?Discipline $discipline = null,
    ) {
    }

    /**
     * Un modificateur global s'applique toujours ; un modificateur de portée ne s'applique
     * qu'à sa discipline. Poser la question ici plutôt que dans chaque consommateur évite
     * qu'un module oublie le cas — et il n'y a qu'une bonne réponse.
     */
    public function appliesTo(ModifierType $type, Discipline $discipline): bool
    {
        return $this->type === $type
            && (null === $this->discipline || $this->discipline === $discipline);
    }
}
