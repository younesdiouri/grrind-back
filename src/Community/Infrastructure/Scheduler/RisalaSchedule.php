<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Scheduler;

use App\Community\Application\RevealRisalat;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Le battement qui fait tourner les Risālāt. Le composant Scheduler de Symfony, pas un cron
 * Fly : une planification qui vit hors du dépôt est une planification qu'aucun test ne voit
 * et que personne ne relit.
 *
 * Le transport s'appelle `scheduler_risala` — c'est le nom de ce planificateur préfixé, la
 * convention du composant — et il est consommé par le worker qui tourne déjà en continu,
 * voir `fly.toml`.
 *
 * **Toutes les heures, et non le dimanche à 20h.** La raison tient en une phrase — la vérité
 * est l'échéance stockée sur le tour, jamais l'instant où le message arrive — et elle est
 * développée dans le docblock de {@see RevealRisalat}. Ce qu'elle achète ici : pas de
 * `stateful()`, donc pas de point de reprise à conserver dans un cache fichier qu'un
 * déploiement efface, et pas de `lock()`, parce qu'un second worker ne peut rien faire de
 * plus qu'un message rejoué — que le handler absorbe déjà.
 *
 * L'expression cron plutôt que `every('1 hour')` : la seconde compte à partir du démarrage du
 * worker, donc elle dériverait à chaque redéploiement et la révélation partirait à 20h37 un
 * dimanche, 20h04 le suivant. `0 * * * *` retombe toujours sur l'heure ronde, donc sur
 * l'échéance elle-même.
 *
 * @see https://symfony.com/doc/current/scheduler.html
 */
#[AsSchedule('risala')]
final class RisalaSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->with(RecurringMessage::cron('0 * * * *', new RevealRisalat()));
    }
}
