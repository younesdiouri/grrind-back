<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildMembership;
use App\Community\Domain\PendingGuildActivity;
use App\Community\Domain\QuietHours;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushSender;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\NotificationCategory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'envoi proprement dit (#133) — jamais avant que {@see PendingGuildActivity} soit
 * refermée, jamais deux fois pour la même annonce : voir le docblock d'
 * {@see AnnounceGuildActivity} pour pourquoi ce détour existe.
 *
 * **Les trois refus se décident par destinataire, jamais par auteur.** Le fuseau des
 * heures calmes, comme le plafond, appartiennent à celui qui reçoit — un joueur peut être
 * notifié pendant qu'un autre, dans un fuseau ou avec un historique différents, ne l'est
 * pas pour la même annonce. Aucun des deux refus n'interrompt les autres destinataires,
 * même logique que `PushSender` sur un jeton mort.
 */
final readonly class AnnounceGuildActivityHandler
{
    public function __construct(
        private PendingGuildActivityRepository $pending,
        private GuildMembershipRepository $memberships,
        private PlayerProfiles $profiles,
        private PlayerTimezones $timezones,
        private PushSender $pushSender,
        private ClockInterface $clock,
        private QuietHours $quietHours,
        /**
         * Nommé pour correspondre au limiteur `guild_activity_push` de
         * `rate_limiter.yaml` — c'est la convention d'autowiring de
         * `symfony/rate-limiter`, pas un choix de ce fichier.
         */
        private RateLimiterFactoryInterface $guildActivityPushLimiter,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceGuildActivity $message): void
    {
        $activity = $this->pending->close($message->authorId);

        // Défensif : `GuildActivityNotifier` ne programme cette annonce que lorsqu'elle
        // vient d'ouvrir la ligne, donc `close()` la trouve normalement toujours. Une
        // absence ne signale rien de plus qu'un rejeu du même message — le #134 tranchera
        // l'idempotence de l'outbox, ce n'est pas à cette classe de le faire en silence.
        if (null === $activity) {
            return;
        }

        $membership = $this->memberships->ofPlayer($message->authorId);

        // L'auteur a quitté sa guilde entre la séance et cette annonce : plus personne à
        // prévenir.
        if (null === $membership) {
            return;
        }

        $recipientIds = self::recipientsOf($membership->guild()->members(), $message->authorId);

        if ([] === $recipientIds) {
            return;
        }

        $profile = $this->profiles->of([$message->authorId])[$message->authorId->toRfc4122()] ?? null;

        // Pas de pseudo, pas d'annonce lisible — voir `PlayerProfiles` : le cas est
        // aujourd'hui impossible (rien ne supprime un compte) mais traité comme partout
        // ailleurs dans ce module, perdre une annonce vaut mieux qu'en publier une à
        // moitié écrite.
        if (null === $profile) {
            return;
        }

        $notification = new PushNotification(
            'Activité de guilde',
            self::body($profile->displayName, $activity),
            NotificationCategory::GuildActivity,
            // Stable par auteur, pas par annonce : la prochaine activité du même joueur
            // remplace celle-ci sur l'appareil du destinataire plutôt que de s'empiler à
            // côté.
            'guild-activity:'.$message->authorId->toRfc4122(),
        );

        $now = $this->clock->now();

        foreach ($recipientIds as $recipientId) {
            if ($this->quietHours->contains($now, $this->timezones->of($recipientId))) {
                continue;
            }

            if (!$this->guildActivityPushLimiter->create($recipientId->toRfc4122())->consume()->isAccepted()) {
                continue;
            }

            $this->pushSender->send($recipientId, $notification);
        }
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

    private static function body(string $authorName, PendingGuildActivity $activity): string
    {
        if (1 === $activity->sessionsCount()) {
            return \sprintf(
                '⚔️ %s : %d min de %s, +%d XP',
                $authorName,
                intdiv($activity->lastDurationSeconds(), 60),
                self::disciplineLabel($activity->lastDiscipline()),
                $activity->totalXpGranted(),
            );
        }

        return \sprintf(
            '⚔️ %s a enregistré %d séances, +%d XP',
            $authorName,
            $activity->sessionsCount(),
            $activity->totalXpGranted(),
        );
    }

    /**
     * Une traduction en dur, et volontairement : c'est le texte d'un push, pas une donnée
     * du contrat API — contrairement à {@see Discipline} lui-même, qui reste anglais et
     * stable pour le client. Le jour où l'app se traduit, ce sera par le même mécanisme
     * que {@see \App\Progression\Infrastructure\Translation\TitleTranslator}, mais un
     * worker asynchrone n'a pas de requête HTTP dont tirer une locale — poser
     * l'infrastructure de traduction ici attendrait un besoin qui n'existe pas encore.
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
        };
    }
}
