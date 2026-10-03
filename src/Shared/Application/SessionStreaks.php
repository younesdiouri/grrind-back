<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\WorkoutImported;

/**
 * Le quatrième geste de la transaction d'import (#286), après l'XP et le loot : la série
 * de régularité. `Training` l'appelle pour chaque séance du `SyncSummary`, `Engagement`
 * l'implémente — même partage que {@see SessionDrops}, et pour la même raison : une
 * matière différente, une place précise dans la séquence.
 *
 * Appelé dans la transaction, sous le verrou de progression déjà tenu : deux imports
 * concurrents du même joueur ne peuvent pas attribuer deux fois le même coffre.
 */
interface SessionStreaks
{
    public function recordFor(WorkoutImported $workout): SessionStreak;
}
