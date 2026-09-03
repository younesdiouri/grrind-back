<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildMembership;
use App\Community\Domain\PendingGuildActivity;
use App\Community\Domain\QuietHours;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Application\PlayerLocales;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'envoi proprement dit (#133) — jamais avant que la fenêtre soit lue via
 * {@see PendingGuildActivityRepository::activityFor()}, jamais deux fois pour le même
 * destinataire : voir le docblock d'{@see AnnounceGuildActivity} pour pourquoi ce détour
 * existe, et celui de {@see \App\Shared\Domain\Notification\NotificationAttempt} pour
 * l'idempotence du #134.
 *
 * **Les trois refus se décident par destinataire, jamais par auteur.** Le fuseau des
 * heures calmes, comme le plafond, appartiennent à celui qui reçoit — un joueur peut être
 * notifié pendant qu'un autre, dans un fuseau ou avec un historique différents, ne l'est
 * pas pour la même annonce. Aucun des deux refus n'interrompt les autres destinataires,
 * même logique que `PushSender` sur un jeton mort.
 *
 * **La fenêtre ne se referme qu'après avoir essayé tous les destinataires — jamais en
 * entrant dans le handler (#134).** L'outbox livre au moins une fois : si ce handler
 * refermait la fenêtre avant d'envoyer, comme avant le #134, un rejeu après une panne au
 * milieu de la boucle (le worker tué, un dixième destinataire sur trente) retomberait sur
 * une fenêtre déjà vide et abandonnerait silencieusement les destinataires restants —
 * l'inverse du problème que le #134 devait fermer, mais tout aussi silencieux. Chaque
 * sortie de cette méthode referme donc explicitement la fenêtre elle-même, qu'il y ait eu
 * un destinataire ou aucun.
 */
final readonly class AnnounceGuildActivityHandler
{
    public function __construct(
        private PendingGuildActivityRepository $pending,
        private GuildMembershipRepository $memberships,
        private PlayerProfiles $profiles,
        private PlayerLocales $locales,
        private PlayerTimezones $timezones,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private ClockInterface $clock,
        private QuietHours $quietHours,
        /**
         * Nommé pour correspondre au limiteur `guild_activity_push` de
         * `rate_limiter.yaml` — c'est la convention d'autowiring de
         * `symfony/rate-limiter`, pas un choix de ce fichier.
         */
        private RateLimiterFactoryInterface $guildActivityPushLimiter,
        private TranslatorInterface $translator,
        private DisciplineTranslator $disciplines,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceGuildActivity $message): void
    {
        $activity = $this->pending->activityFor($message->authorId, $message->windowId);

        // `null` veut dire qu'il n'y a rien à faire pour *cette* fenêtre : soit un rejeu
        // après un traitement déjà complet (elle a été refermée en sortie de méthode la
        // fois précédente), soit une fenêtre plus récente a déjà pris sa place pour le même
        // auteur (mode dégradé). Dans les deux cas, refermer quoi que ce soit ici serait une
        // erreur — voir `PendingGuildActivityRepository::activityFor()`.
        if (null === $activity) {
            return;
        }

        $membership = $this->memberships->ofPlayer($message->authorId);

        // L'auteur a quitté sa guilde entre la séance et cette annonce : plus personne à
        // prévenir.
        if (null === $membership) {
            $this->pending->close($message->authorId, $message->windowId);

            return;
        }

        $recipientIds = self::recipientsOf($membership->guild()->members(), $message->authorId);

        if ([] === $recipientIds) {
            $this->pending->close($message->authorId, $message->windowId);

            return;
        }

        $profile = $this->profiles->of([$message->authorId])[$message->authorId->toRfc4122()] ?? null;

        // Pas de pseudo, pas d'annonce lisible — voir `PlayerProfiles` : le cas est
        // aujourd'hui impossible (rien ne supprime un compte) mais traité comme partout
        // ailleurs dans ce module, perdre une annonce vaut mieux qu'en publier une à
        // moitié écrite.
        if (null === $profile) {
            $this->pending->close($message->authorId, $message->windowId);

            return;
        }

        $now = $this->clock->now();

        foreach ($recipientIds as $recipientId) {
            if ($this->quietHours->contains($now, $this->timezones->of($recipientId))) {
                continue;
            }

            if (!$this->guildActivityPushLimiter->create($recipientId->toRfc4122())->consume()->isAccepted()) {
                continue;
            }

            $notification = $this->notificationFor($recipientId, $message->authorId, $profile->displayName, $activity);

            // Écrite avant l'appel réseau, en contrainte d'unicité (#134) : l'outbox livre
            // au moins une fois, donc c'est cette réservation — pas un espoir que ce
            // handler ne rejoue jamais — qui empêche un retry de renotifier un destinataire
            // déjà servi. Une collision veut dire « déjà envoyé », pas une erreur : on
            // passe au suivant sans y toucher.
            if (!$this->attempts->claim($message->windowId, $recipientId, $notification->category, $now)) {
                continue;
            }

            $this->pushSender->send($recipientId, $notification);
        }

        $this->pending->close($message->authorId, $message->windowId);
    }

    /**
     * @param list<GuildMembership> $members
     *
     * @return list<Uuid>
     */
    private static function recipientsOf(array $members, Uuid $authorId): array
    {
        $recipients = [];

        foreach ($members as $member) {
            if (!$member->playerId()->equals($authorId)) {
                $recipients[] = $member->playerId();
            }
        }

        return $recipients;
    }

    private function notificationFor(Uuid $recipientId, Uuid $authorId, string $authorName, PendingGuildActivity $activity): PushNotification
    {
        $locale = $this->locales->localeOf($recipientId);

        if (1 === $activity->sessionsCount()) {
            $body = $this->translator->trans('guild_activity.single', [
                '%author%' => $authorName,
                '%minutes%' => intdiv($activity->lastDurationSeconds(), 60),
                '%discipline%' => $this->disciplines->labelOf($activity->lastDiscipline(), $locale),
                '%xp%' => $activity->totalXpGranted(),
            ], 'messages', $locale);
        } else {
            $body = $this->translator->trans('guild_activity.multiple', [
                '%author%' => $authorName,
                '%sessions%' => $activity->sessionsCount(),
                '%xp%' => $activity->totalXpGranted(),
            ], 'messages', $locale);
        }

        return new PushNotification(
            $this->translator->trans('guild_activity.title', domain: 'messages', locale: $locale),
            $body,
            NotificationCategory::GuildActivity,
            'guild-activity:'.$authorId->toRfc4122(),
            new PushRoute(PushRouteType::PlayerProfile, $authorId),
        );
    }
}
