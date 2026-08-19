<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Domain\Event\WorkoutCredited;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * La séance créditée réveille la guilde — le point d'entrée du #133, abonné à
 * {@see WorkoutCredited} sur l'outbox comme tout consommateur inter-module : après un
 * `COMMIT` réussi, jamais depuis un contrôleur, sinon on annoncerait un fait encore
 * annulable.
 *
 * **N'envoie jamais rien elle-même.** Elle ne décide que de deux choses — la séance
 * est-elle assez fraîche pour valoir une annonce, l'auteur a-t-il une guilde — et confie le
 * reste à {@see \App\Community\Domain\PendingGuildActivity}. L'envoi proprement dit attend
 * {@see AnnounceGuildActivityHandler} : voir le docblock d'{@see AnnounceGuildActivity}
 * pour pourquoi une séance créditée ne déclenche jamais un envoi immédiat — un push par
 * séance est exactement ce que le ticket met en garde contre — et pour ce que le
 * `DelayStamp` posé ci-dessous garantit réellement.
 */
final readonly class GuildActivityNotifier
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private PendingGuildActivityRepository $pending,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private int $freshnessWindowMinutes,
        private int $announcementDelaySeconds,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(WorkoutCredited $event): void
    {
        if (!$this->isFresh($event->endedAt)) {
            return;
        }

        // Pas de guilde, pas de destinataire : inutile d'ouvrir une annonce que personne
        // ne recevra jamais. `AnnounceGuildActivityHandler` referait le même constat à
        // l'envoi, mais le faire ici évite une ligne et un message pour la plupart des
        // comptes, qui n'ont pas encore rejoint de guilde.
        if (null === $this->memberships->ofPlayer($event->userId)) {
            return;
        }

        $windowId = $this->pending->recordSession(
            $event->userId,
            $event->discipline,
            $event->durationSeconds,
            $event->xpGranted,
        );

        // Une annonce est déjà programmée pour cet auteur : elle lira cette séance-ci en
        // plus des précédentes à son tour, inutile d'en programmer une seconde.
        if (null === $windowId) {
            return;
        }

        // Le délai n'existe pas pour laisser le temps à d'autres séances d'arriver — voir
        // le docblock d'`AnnounceGuildActivity` : sans lui, deux messages publiés dans la
        // même seconde n'ont aucun ordre garanti entre eux, et cette annonce pourrait être
        // traitée *avant* une séance déjà en file pour le même lot.
        $this->bus->dispatch(new AnnounceGuildActivity($event->userId, $windowId), [new DelayStamp($this->announcementDelaySeconds * 1000)]);
    }

    private function isFresh(DateTimeImmutable $endedAt): bool
    {
        $ageInMinutes = ($this->clock->now()->getTimestamp() - $endedAt->getTimestamp()) / 60;

        return $ageInMinutes <= $this->freshnessWindowMinutes;
    }
}
