<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Shared\Domain\Activity\VitalityBreakdown;

/**
 * La Vitality bonifiée d'un joueur (#165), et de quoi expliquer le bonus au client — le
 * rendu de {@see VitalityBonusProvider::of()} pour un seul joueur.
 *
 * Deux champs plutôt qu'un entier nu : `value` est ce qui remplace la Vitality lue du
 * snapshot dans toute réponse qui l'affiche, `breakdown` est ce qui répond à « pourquoi »
 * — voir le docblock de {@see VitalityBreakdown}.
 */
final readonly class BonusedVitality
{
    public function __construct(
        public int $value,
        public VitalityBreakdown $breakdown,
    ) {
    }
}
