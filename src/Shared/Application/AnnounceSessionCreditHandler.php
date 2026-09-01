<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Notification\PendingSessionCredit;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * L'envoi proprement dit (#252) — le pendant, pour l'auteur lui-même, de
 * `App\Community\Application\AnnounceGuildActivityHandler`. Jamais avant que la fenêtre soit
 * lue via {@see PendingSessionCreditRepository::activityFor()}, jamais deux fois pour le même
 * joueur : voir le docblock d'{@see AnnounceSessionCredit} pour pourquoi ce détour existe, et
 * celui de {@see \App\Shared\Domain\Notification\NotificationAttempt} pour l'idempotence du
 * #134, réutilisée ici à l'identique — un seul destinataire au lieu de N, mais la même trace
 * de livraison protège contre le même rejeu.
 *
 * **Aucune heure calme ici — voir le docblock de {@see SessionCreditedNotifier} pour
 * pourquoi cette catégorie diverge des trois autres.** C'est la différence structurelle avec
 * `AnnounceGuildActivityHandler` : pas de `QuietHours`, pas de `PlayerTimezones`, pas de
 * boucle sur des destinataires — il n'y en a qu'un, celui qui a fait la séance.
 *
 * **La fenêtre ne se referme qu'après l'envoi, jamais en entrant dans la méthode** — même
 * geste qu'`AnnounceGuildActivityHandler`, pour la même raison (#134) : l'outbox livre au
 * moins une fois, refermer avant enverrait l'exact problème inverse en cas de panne.
 *
 * **Pas de loot dans le corps du message, malgré ce que suggérait le ticket.** `WorkoutCredited`
 * ne porte que la discipline, la durée, l'XP et le palier avant/après — le détail du tirage
 * reste dans `SessionReward`, hors de portée d'un abonné asynchrone (voir le docblock de
 * `WorkoutCredited`). Le niveau franchi, lui, est disponible et apparaît quand la fenêtre en
 * a traversé un.
 */
final readonly class AnnounceSessionCreditHandler
{
    public function __construct(
        private PendingSessionCreditRepository $pending,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceSessionCredit $message): void
    {
        $activity = $this->pending->activityFor($message->playerId, $message->windowId);

        // `null` : rejeu après un traitement déjà complet, ou fenêtre déjà remplacée par une
        // plus récente (mode dégradé) — même lecture qu'`AnnounceGuildActivityHandler`. Rien
        // à refermer dans les deux cas.
        if (null === $activity) {
            return;
        }

        $notification = new PushNotification(
            'Bien joué !',
            self::body($activity),
            NotificationCategory::SessionCredited,
            // Stable par joueur : la prochaine séance créditée remplace ce push sur
            // l'appareil plutôt que de s'empiler à côté.
            'session-credited:'.$message->playerId->toRfc4122(),
            // v1 : soi-même. La route existe et est autorisée pour son propre profil ; une
            // cible dédiée qui rejoue le `RewardSummary` attendra que le client sache le
            // faire (#252).
            new PushRoute(PushRouteType::PlayerProfile, $message->playerId),
        );

        $now = $this->clock->now();

        // Réservée avant l'appel réseau, même ordre qu'`AnnounceGuildActivityHandler` (#149) :
        // une collision veut dire « déjà envoyé », pas une erreur.
        if ($this->attempts->claim($message->windowId, $message->playerId, $notification->category, $now)) {
            $this->pushSender->send($message->playerId, $notification);
        }

        $this->pending->close($message->playerId, $message->windowId);
    }

    private static function body(PendingSessionCredit $activity): string
    {
        $body = 1 === $activity->sessionsCount()
            ? \sprintf(
                '⚔️ %d min de %s, +%d XP',
                intdiv($activity->lastDurationSeconds(), 60),
                self::disciplineLabel($activity->lastDiscipline()),
                $activity->totalXpGranted(),
            )
            : \sprintf(
                '⚔️ Tu as enregistré %d séances, +%d XP',
                $activity->sessionsCount(),
                $activity->totalXpGranted(),
            );

        if ($activity->leveledUp()) {
            $body .= \sprintf(' — 🎉 Niveau %d !', $activity->currentLevel());
        }

        return $body;
    }

    /**
     * Une traduction en dur, et volontairement — même remarque qu'à
     * `AnnounceGuildActivityHandler::disciplineLabel()`, dont le dictionnaire est repris
     * mot pour mot : c'est le texte d'un push, pas une donnée du contrat API, et la même
     * discipline doit se lire pareil qu'on l'apprenne de son propre push ou de celui d'un
     * co-équipier.
     */
    private static function disciplineLabel(Discipline $discipline): string
    {
        return match ($discipline) {
            Discipline::Running => 'course',
            Discipline::Walking => 'marche',
            Discipline::Cycling => 'vélo',
            Discipline::Swimming => 'natation',
            Discipline::Strength => 'musculation',
            Discipline::Hiit => 'HIIT',
            Discipline::Hiking => 'randonnée',
            Discipline::Mobility => 'mobilité',
            Discipline::Climbing => 'escalade',
            Discipline::Football => 'football',
            Discipline::CourtSports => 'sport de salle',
            Discipline::RacketSports => 'sport de raquette',
        };
    }
}
