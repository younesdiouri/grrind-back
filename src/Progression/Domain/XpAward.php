<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Ce qu'une séance a rapporté, et sous quelles règles.
 *
 * La `rulesetVersion` voyage avec le montant, pas à côté : c'est ce qui garantit qu'une
 * écriture au ledger ne peut pas être datée des règles d'un autre calcul. Le jour où
 * l'équilibrage bouge, l'historique reste lisible parce que chaque montant dit sous quel
 * barème il a été accordé.
 */
final readonly class XpAward
{
    public function __construct(
        public XpBreakdown $breakdown,
        public string $rulesetVersion,
    ) {
    }

    public function amount(): int
    {
        return $this->breakdown->total();
    }
}
