<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Ce que le joueur a déjà fait aujourd'hui, dans **son** fuseau. L'entrée qui place une
 * séance sur la courbe des rendements décroissants et sous le plafond de sa discipline.
 *
 * Valeur pure : `XpCalculator` la reçoit et ne va la chercher nulle part. C'est ce qui
 * garde le calcul rejouable — un montant de l'an dernier se réexplique en fournissant le
 * contexte de ce jour-là, pas en espérant que la base n'ait pas bougé.
 *
 * Les deux compteurs n'ont pas la même portée, et c'est voulu : le temps se cumule **toutes
 * disciplines confondues** — c'est le volume d'entraînement quotidien qui décroît — tandis
 * que le plafond d'XP est **par discipline**, pour qu'on ne puisse pas tout concentrer sur
 * la mieux payée.
 */
final readonly class DailyLoad
{
    public function __construct(
        /** Secondes d'entraînement déjà créditées aujourd'hui, toutes disciplines. */
        public int $secondsSoFar,
        /** XP déjà accordée aujourd'hui **dans la discipline de la séance en cours**. */
        public int $xpSoFarInDiscipline,
    ) {
    }

    /** Le premier entraînement de la journée. */
    public static function untouched(): self
    {
        return new self(0, 0);
    }
}
