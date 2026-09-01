<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\WorkoutCredited;
use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * La séance créditée appelle son propre auteur (#252) — le pendant, pour lui-même, de
 * `App\Community\Application\GuildActivityNotifier` pour ses co-équipiers. Abonné à
 * {@see WorkoutCredited} sur l'outbox comme tout consommateur inter-module : après un
 * `COMMIT` réussi, jamais depuis le contrôleur d'import, sinon on annoncerait un fait
 * encore annulable.
 *
 * **Pourquoi ici, dans `Shared`, et pas dans un module métier — un choix, pas une
 * contrainte.** `Community`, `Identity` et `Progression` dépendent tous de `Shared`
 * (`deptrac.yaml`), et tout ce que ce mécanisme consomme y vit déjà : {@see WorkoutCredited},
 * `PushSender`, `NotificationAttempt`, `NotificationCategory`. N'importe lequel des trois
 * aurait donc pu l'héberger — `Community` le premier, puisqu'il héberge exactement de cette
 * façon {@see \App\Community\Application\GuildActivityNotifier}, qui consomme le même
 * événement. Rien dans les frontières ne tranchait à notre place.
 *
 * `Shared` l'emporte parce que le destinataire *est* l'auteur : rien ici ne dépend d'une
 * guilde, d'un profil ni d'aucune donnée propre à un module, donc aucun module ne possède
 * cette décision mieux qu'un autre, et la machinerie de push est déjà entière ici.
 *
 * Le corollaire s'assume plutôt qu'il ne se cache : `Shared` étant visible de tous, plus
 * aucune frontière Deptrac ne protège cette entité. Le jour où un module se met à en
 * dépendre, ce n'est pas une violation — c'est le signe qu'elle a changé de nature et
 * qu'elle doit déménager chez lui.
 *
 * **Deux divergences assumées avec `GuildActivityNotifier`, tranchées par le ticket #252 et
 * non rouvertes ici :**
 * - **les heures calmes ne s'appliquent pas.** Elles existent pour que l'activité de
 *   *quelqu'un d'autre* ne réveille personne ; la propre séance qu'on vient de terminer
 *   n'est pas une intrusion, et faire taire sa récompense parce qu'il est tôt punirait le
 *   lève-tôt. `AnnounceSessionCreditHandler` n'a donc, contrairement à
 *   `AnnounceGuildActivityHandler`, ni `QuietHours` ni `PlayerTimezones` en dépendance ;
 * - **la fenêtre de fraîcheur, elle, s'applique**, avec la même valeur
 *   (`freshness_window_minutes`) : le premier import d'un compte contient parfois trois ans
 *   d'Apple Health, et « Bien joué ! » sur une séance de 2023 serait absurde.
 *
 * **N'envoie jamais rien elle-même**, même geste que `GuildActivityNotifier` : elle ne
 * décide que « cette séance est-elle assez fraîche » et confie le reste à
 * {@see \App\Shared\Domain\Notification\PendingSessionCredit}. L'envoi attend
 * {@see AnnounceSessionCreditHandler}, retardé par le `DelayStamp` posé ci-dessous — voir
 * le docblock d'{@see AnnounceSessionCredit} pour ce qu'il garantit réellement.
 */
final readonly class SessionCreditedNotifier
{
    public function __construct(
        private PendingSessionCreditRepository $pending,
        // Sans `#[Target]`, volontairement — même raison que `GuildActivityNotifier` (#155) :
        // `AnnounceSessionCredit` n'est pas un `DomainEvent`, il doit rester sur le bus
        // strict que `MessageBusInterface` résout par défaut.
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

        $windowId = $this->pending->recordSession(
            $event->userId,
            $event->discipline,
            $event->durationSeconds,
            $event->xpGranted,
            $event->levelBefore,
            $event->levelAfter,
            $this->clock->now(),
        );

        // `null` : une notification fraîche est déjà programmée pour ce joueur, elle lira
        // cette séance-ci en plus des précédentes à son tour — même raison qu'à
        // `GuildActivityNotifier`.
        if (null === $windowId) {
            return;
        }

        // Le délai garantit l'ordre, pas l'agrégation elle-même — voir le docblock
        // d'`AnnounceSessionCredit`.
        $this->bus->dispatch(new AnnounceSessionCredit($event->userId, $windowId), [new DelayStamp($this->announcementDelaySeconds * 1000)]);
    }

    private function isFresh(DateTimeImmutable $endedAt): bool
    {
        $ageInMinutes = ($this->clock->now()->getTimestamp() - $endedAt->getTimestamp()) / 60;

        return $ageInMinutes <= $this->freshnessWindowMinutes;
    }
}
