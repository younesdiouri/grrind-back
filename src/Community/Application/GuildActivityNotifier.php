<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Application\GameRulesets;
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
final class GuildActivityNotifier
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private PendingGuildActivityRepository $pending,
        // Sans `#[Target]`, volontairement (#155) : `AnnounceGuildActivity` n'est pas un
        // `DomainEvent`, il doit rester sur le bus strict — celui que `MessageBusInterface`
        // résout par défaut. Symfony ne crée d'alias nommé que pour les bus non par
        // défaut ; en poser un ici pour `command.bus` ne compile pas, voir le docblock de
        // `messenger.yaml`.
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private GameRulesets $rulesets,
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
            $this->clock->now(),
        );

        // `null` : une annonce fraîche est déjà programmée pour cet auteur, elle lira
        // cette séance-ci en plus des précédentes à son tour — inutile d'en reprogrammer
        // une seconde. Un `windowId` non nul, en revanche, ne veut pas toujours dire
        // « fenêtre neuve » : voir le docblock de `PendingGuildActivityRepository::recordSession()`
        // (#134) — une fenêtre abandonnée (handler qui a épuisé ses trois tentatives) en
        // rend un aussi, pour qu'une annonce reparte. Cette méthode n'a pas à distinguer
        // les deux cas : dans les deux, une annonce doit partir.
        if (null === $windowId) {
            return;
        }

        // Le délai n'existe pas pour laisser le temps à d'autres séances d'arriver — voir
        // le docblock d'`AnnounceGuildActivity` : sans lui, deux messages publiés dans la
        // même seconde n'ont aucun ordre garanti entre eux, et cette annonce pourrait être
        // traitée *avant* une séance déjà en file pour le même lot.
        $this->bus->dispatch(new AnnounceGuildActivity($event->userId, $windowId), [new DelayStamp($this->notificationSettings()['announcement_delay_seconds'] * 1000)]);
    }

    private function isFresh(DateTimeImmutable $endedAt): bool
    {
        $ageInMinutes = ($this->clock->now()->getTimestamp() - $endedAt->getTimestamp()) / 60;

        return $ageInMinutes <= $this->notificationSettings()['freshness_window_minutes'];
    }

    /** @return array{freshness_window_minutes: int, announcement_delay_seconds: int, stale_window_minutes: int} */
    private function notificationSettings(): array
    {
        $snapshot = $this->rulesets->snapshot();
        /** @var array{freshness_window_minutes: int, announcement_delay_seconds: int, stale_window_minutes: int} $notifications */
        $notifications = $snapshot['notifications'];

        return $notifications;
    }
}
