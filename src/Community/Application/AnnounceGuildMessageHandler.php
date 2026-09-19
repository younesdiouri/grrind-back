<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildMessage;
use App\Community\Domain\QuietHours;
use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use App\Shared\Application\PlayerLocales;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le push d'un message de chat (#281).
 *
 * **Aucune fenêtre d'agrégation, et c'est la décision inverse du #133.** Une annonce de
 * séance est agrégée parce que dix séances d'un même auteur sont le même fait répété ; dix
 * messages sont dix phrases, et les fondre en « 10 nouveaux messages » rend la notification
 * exactement aussi utile que pas de notification du tout — il faut ouvrir l'app pour savoir
 * si ça valait la peine. Ce que `groupingKey` fait à la place est plus modeste et suffit :
 * la dernière remplace la précédente dans le centre de notifications, sans rien cacher de
 * son contenu.
 *
 * **Les heures calmes s'appliquent, et ce qu'elles écartent est perdu — jamais reporté.**
 * `AnnounceRisalaTurn` se reporte à leur sortie parce qu'il demande d'agir avant une
 * échéance ; un message, lui, a déjà sa destination durable — le chat, qui le garde. Le
 * repousser à 8 h ferait sonner la nuit d'hier au petit matin, ce qui est le contraire de
 * ce que le joueur a demandé.
 *
 * **Le destinataire est relu ici, jamais transporté.** Le message ne porte que l'identifiant
 * de la ligne : quelqu'un qui quitte la guilde entre l'envoi et la consommation ne reçoit
 * rien, et quelqu'un qui la rejoint dans le même intervalle reçoit — c'est l'adhésion au
 * moment de l'envoi du push qui décide, la seule que ce handler puisse constater.
 */
final readonly class AnnounceGuildMessageHandler
{
    /** Ce qui tient sur l'écran verrouillé sans que le système le coupe au milieu d'un mot. */
    private const int BODY_LIMIT = 120;

    public function __construct(
        private GuildMessageRepository $messages,
        private PlayerProfiles $profiles,
        private PlayerLocales $locales,
        private PlayerTimezones $timezones,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private ClockInterface $clock,
        private QuietHours $quietHours,
        /** Nommé d'après le limiteur `guild_chat_push` de `rate_limiter.yaml` — convention d'autowiring de `symfony/rate-limiter`. */
        private RateLimiterFactoryInterface $guildChatPushLimiter,
        private TranslatorInterface $translator,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceGuildMessage $announcement): void
    {
        $message = $this->messages->ofId(Uuid::fromString($announcement->messageId));

        // La ligne a disparu entre l'envoi et la consommation. Rien à annoncer, et surtout
        // rien à reconstituer : le contenu n'a jamais quitté la base.
        if (null === $message) {
            return;
        }

        $author = $message->authorId();
        $profile = $this->profiles->of([$author])[$author->toRfc4122()] ?? null;

        // Sans pseudo, le corps dirait « : bonjour ». Perdre l'annonce vaut mieux — même
        // arbitrage que `AnnounceGuildActivityHandler`.
        if (null === $profile) {
            return;
        }

        $now = $this->clock->now();
        $guild = $message->guild();

        foreach ($guild->members() as $member) {
            $recipient = $member->playerId();
            if ($recipient->equals($author)) {
                continue;
            }
            if ($this->quietHours->contains($now, $this->timezones->of($recipient))) {
                continue;
            }
            if (!$this->guildChatPushLimiter->create($recipient->toRfc4122())->consume()->isAccepted()) {
                continue;
            }

            // Réservée avant l'appel réseau : l'outbox livre au moins une fois, et c'est
            // cette contrainte d'unicité — pas l'espoir que le handler ne rejoue jamais —
            // qui empêche un rejeu de renotifier quelqu'un. L'identifiant du message joue
            // ici le rôle que `windowId` joue pour une annonce d'activité.
            if (!$this->attempts->claim($message->id(), $recipient, NotificationCategory::GuildChat, $now)) {
                continue;
            }

            $this->pushSender->send($recipient, $this->notificationFor($recipient, $guild->name(), $profile->displayName, $message));
        }
    }

    private function notificationFor(Uuid $recipient, string $guildName, string $authorName, GuildMessage $message): PushNotification
    {
        $locale = $this->locales->localeOf($recipient);
        $text = trim($message->text());
        $body = '' === $text
            ? $this->translator->trans('guild_chat.image', ['%author%' => $authorName], 'messages', $locale)
            : $this->translator->trans('guild_chat.message', ['%author%' => $authorName, '%text%' => self::shortened($text)], 'messages', $locale);

        return new PushNotification(
            // Le nom de la guilde, et non un libellé traduit : une notification de
            // conversation nomme la conversation. C'est aussi ce qui distingue deux
            // guildes le jour où un compte en aura plusieurs.
            $guildName,
            $body,
            NotificationCategory::GuildChat,
            // Toute la guilde partage la clé : le dernier message remplace le précédent au
            // lieu d'empiler la conversation. C'est bien le fil qu'on remplace, pas
            // l'auteur — deux personnes qui se répondent produisent un seul bandeau.
            'guild-chat:'.$message->guild()->id()->toRfc4122(),
            new PushRoute(PushRouteType::GuildChat, $message->guild()->id()),
        );
    }

    /** Coupé sur un espace quand il y en a un à portée : « Je passe à la sal… » se lit mieux que « Je passe à la sal ». */
    private static function shortened(string $text): string
    {
        if (mb_strlen($text) <= self::BODY_LIMIT) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::BODY_LIMIT);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim(false === $lastSpace || $lastSpace < self::BODY_LIMIT - 20 ? $cut : mb_substr($cut, 0, $lastSpace)).'…';
    }
}
