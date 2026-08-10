<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

use App\Shared\Domain\Event\TrainingSessionCompleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Un module tiers, en réduction. Il tient lieu de `Progression` tant que celui-ci
 * n'existe pas, et il est ici pour prouver le « fini quand » du ticket : réagir à une
 * séance terminée sans qu'aucune ligne de `Training` ne le mentionne.
 *
 * Un `#[AsMessageHandler]`, un type-hint sur l'événement de `Shared`, et rien d'autre —
 * pas de configuration, pas d'enregistrement, aucune référence dans les deux sens.
 *
 * Ce qu'il a reçu est statique, et c'est délibéré : le worker s'exécute dans son propre
 * cycle, et aller rechercher l'instance que le conteneur lui a donnée ferait du test une
 * enquête sur l'injection de dépendances plutôt que sur le routage des événements.
 */
#[AsMessageHandler]
final class SessionCompletedSpy
{
    /** @var list<TrainingSessionCompleted> */
    public static array $received = [];

    public function __invoke(TrainingSessionCompleted $event): void
    {
        self::$received[] = $event;
    }

    public static function forget(): void
    {
        self::$received = [];
    }
}
