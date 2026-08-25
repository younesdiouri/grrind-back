<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\AttributeGains;

/**
 * Ce qu'une séance a rapporté, et sous quelles règles.
 *
 * La `rulesetVersion` voyage avec le montant, pas à côté : c'est ce qui garantit qu'une
 * écriture au ledger ne peut pas être datée des règles d'un autre calcul. Le jour où
 * l'équilibrage bouge, l'historique reste lisible parce que chaque montant dit sous quel
 * barème il a été accordé.
 *
 * `attributeGains` est la répartition du montant final — `attributeGains.total() ===
 * $this->amount()`, toujours (#159). Elle voyage à côté du breakdown plutôt que dedans :
 * le breakdown explique *comment* le montant a été calculé, la répartition dit *vers où*
 * il va sur la fiche du personnage — deux questions différentes, deux champs différents.
 */
final readonly class XpAward
{
    public function __construct(
        public XpBreakdown $breakdown,
        public AttributeGains $attributeGains,
        public string $rulesetVersion,
    ) {
    }

    public function amount(): int
    {
        return $this->breakdown->total();
    }
}
