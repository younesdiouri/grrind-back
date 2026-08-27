<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Infrastructure\Doctrine\DailyActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Écrit — ou révise — les journées d'un lot, une par une.
 *
 * **Aucune valeur de jeu ici, et c'est le point du #165.** Contrairement à
 * {@see \App\Progression\Application\GrantXpHandler}, il n'y a ni verrou de progression à
 * poser ni ledger à écrire : `daily_activity` n'est lue qu'à la demande, par
 * {@see \App\Shared\Application\ActiveEnergyWindows}, jamais créditée ici. Une journée qui
 * échoue à s'écrire n'a donc rien à défaire ailleurs.
 *
 * **Une seule transaction pour le lot entier**, malgré tout : chaque `upsert()` est déjà
 * atomique seul, mais un client qui envoie sept jours d'un coup doit les voir tous
 * appliqués ou aucun — une panne à mi-lot laissant trois jours à jour et quatre à l'ancienne
 * valeur romprait la seule promesse de cette route, « la dernière valeur envoyée gagne ».
 */
final readonly class UpsertDailyActivityHandler
{
    public function __construct(
        private DailyActivityRepository $activities,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpsertDailyActivity $command): void
    {
        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($command, $now): void {
            foreach ($command->entries as $entry) {
                $this->activities->upsert($command->userId, $entry->day, $entry->activeEnergyKcal, $entry->source, $now);
            }
        });
    }
}
