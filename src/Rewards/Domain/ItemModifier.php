<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\ModifierType;

/**
 * Un effet porté par un objet du catalogue — pas encore un {@see
 * \App\Shared\Domain\Modifier\Modifier} : celui-là exige une {@see
 * \App\Shared\Domain\Modifier\ModifierSource}, et un objet dans le catalogue n'est équipé
 * par personne. C'est au futur contributeur de `Rewards` (#29, quand l'inventaire existe)
 * de faire la conversion pour chaque objet équipé, avec `ModifierSource::Item`.
 *
 * **Le vocabulaire est celui de `Shared`, jamais un mécanisme parallèle.** `$type` se
 * valide contre `ModifierType::tryFrom()`, pas contre une liste recopiée ici : le jour où
 * le #224 ouvre les caractéristiques pures et les stats de combat, un objet qui les porte
 * entre dans `items.yaml` sans qu'une ligne bouge dans cette classe ni dans {@see
 * \App\Rewards\Infrastructure\Config\ItemsSection}.
 */
final readonly class ItemModifier
{
    public function __construct(
        public ModifierType $type,
        public int $value,
        /** `null` = global, comme {@see \App\Shared\Domain\Modifier\Modifier}. */
        public ?Discipline $discipline = null,
    ) {
    }
}
