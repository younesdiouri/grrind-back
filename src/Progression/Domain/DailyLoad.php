<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Ce que le joueur a déjà gagné aujourd'hui, dans **son** fuseau. L'entrée qui place une
 * séance sous le plafond de sa discipline.
 *
 * Valeur pure : `XpCalculator` la reçoit et ne va la chercher nulle part. C'est ce qui
 * garde le calcul rejouable — un montant de l'an dernier se réexplique en fournissant le
 * contexte de ce jour-là, pas en espérant que la base n'ait pas bougé.
 *
 * Le cumul de secondes de la journée a disparu avec les rendements décroissants (#344) :
 * deux ou trois séances dans la journée valent chacune leur durée pleine.
 */
final readonly class DailyLoad
{
    public function __construct(
        /** XP déjà accordée aujourd'hui **dans la discipline de la séance en cours**. */
        public int $xpSoFarInDiscipline,
    ) {
    }

    /** Le premier entraînement de la journée. */
    public static function untouched(): self
    {
        return new self(0);
    }
}
