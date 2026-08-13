<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

use App\Shared\Domain\Event\WorkoutImported;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Un module tiers, en réduction. Il est ici pour prouver qu'on peut réagir à un workout
 * importé sans qu'aucune ligne de `Training` ne le mentionne — c'est la promesse que
 * Deptrac fait tenir, et que les classements du Lot 8 encaisseront.
 *
 * Un `#[AsMessageHandler]`, un type-hint sur l'événement de `Shared`, et rien d'autre : pas
 * de configuration, pas d'enregistrement, aucune référence dans les deux sens.
 *
 * Ce qu'il a reçu est statique, et c'est délibéré : le worker s'exécute dans son propre
 * cycle, et aller rechercher l'instance que le conteneur lui a donnée ferait du test une
 * enquête sur l'injection de dépendances plutôt que sur le routage des événements.
 */
#[AsMessageHandler]
final class WorkoutImportedSpy
{
    /** @var list<WorkoutImported> */
    public static array $received = [];

    public function __invoke(WorkoutImported $event): void
    {
        self::$received[] = $event;
    }

    public static function forget(): void
    {
        self::$received = [];
    }
}
