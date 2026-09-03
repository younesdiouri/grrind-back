<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildMembership;
use App\Community\Domain\QuietHours;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRules;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\PlayerLocales;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'annonce de la semaine, à toute la guilde : « Younes envoie Escalade ».
 *
 * ## Ce qu'elle ne fait pas, et pourquoi
 *
 * **Pas de limiteur de débit**, contrairement à {@see AnnounceGuildActivityHandler}. Là-bas il
 * protège d'un joueur qui synchronise dix séances ; ici il n'y a rien à protéger — une annonce
 * par guilde et par semaine — et consommer le même budget que l'activité de guilde ferait taire
 * l'une au profit de l'autre pour rien.
 *
 * **Les heures calmes sont respectées, et l'annonce se perd si elles sont ouvertes.** C'est une
 * information durable : la Risāla est dans l'app au réveil, avec ses quinze jours devant elle.
 * Seule celle du tour se reporte ({@see AnnounceRisalaTurnHandler}), parce qu'elle, elle a une
 * échéance.
 *
 * **L'expéditeur est notifié comme les autres.** Il sait ce qu'il a choisi, mais pas *quand* ça
 * partait, et c'est le moment social de la semaine : l'exclure lui apprendrait qu'il ne fait
 * plus tout à fait partie de la guilde pendant deux secondes.
 *
 * ## Idempotence
 *
 * L'outbox livre au moins une fois. La réservation est prise sur `(risalaId, destinataire,
 * catégorie)` avant l'appel réseau — comme au #134 — donc un rejeu après une panne au milieu de
 * la boucle reprend là où il s'était arrêté sans renotifier personne.
 */
final readonly class AnnounceRisalaHandler
{
    public function __construct(
        private RisalaRepository $risalat,
        private PlayerProfiles $profiles,
        private PlayerLocales $locales,
        private PlayerTimezones $timezones,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private QuietHours $quietHours,
        private RisalaRules $rules,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
        private DisciplineTranslator $disciplines,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceRisala $message): void
    {
        $risala = $this->risalat->find($message->risalaId);

        // La guilde a été dissoute entre la révélation et l'annonce : la Risāla est partie
        // avec elle (`ON DELETE CASCADE`), il n'y a plus personne à prévenir.
        if (!$risala instanceof Risala) {
            return;
        }

        $discipline = $risala->discipline();

        if (null === $discipline) {
            return;
        }

        $sender = $this->profiles->of([$risala->senderId()])[$risala->senderId()->toRfc4122()] ?? null;

        // Pas de pseudo, pas d'annonce lisible — même règle que partout dans ce module :
        // perdre une annonce vaut mieux qu'en publier une à moitié écrite.
        if (null === $sender) {
            return;
        }

        $now = $this->clock->now();

        foreach ($risala->guild()->members() as $member) {
            $this->announceTo($member, $sender->displayName, $discipline, $risala, $now);
        }
    }

    private function announceTo(GuildMembership $member, string $senderName, Discipline $discipline, Risala $risala, DateTimeImmutable $now): void
    {
        $recipientId = $member->playerId();

        // Les heures calmes appartiennent au destinataire, jamais à l'auteur ni au serveur
        // (#133) : 20h à Paris est 3h du matin à Tokyo, et l'annonce est durable — elle sera
        // dans l'app au réveil.
        if ($this->quietHours->contains($now, $this->timezones->of($recipientId))) {
            return;
        }

        if (!$this->attempts->claim($risala->id(), $recipientId, NotificationCategory::RisalaRevealed, $now)) {
            return;
        }

        $this->pushSender->send($recipientId, $this->notificationFor($recipientId, $senderName, $discipline, $risala));
    }

    private function notificationFor(Uuid $recipientId, string $senderName, Discipline $discipline, Risala $risala): PushNotification
    {
        $locale = $this->locales->localeOf($recipientId)->value;

        return new PushNotification(
            $this->translator->trans('risala_revealed.title', domain: 'messages', locale: $locale),
            $this->translator->trans('risala_revealed.body', [
                '%author%' => $senderName,
                '%discipline%' => $this->disciplines->labelOf($discipline, $locale),
                '%bonus%' => $this->rules->recipientBonusPercent(),
            ], 'messages', $locale),
            NotificationCategory::RisalaRevealed,
            'risala:'.$risala->guild()->id()->toRfc4122(),
            new PushRoute(PushRouteType::GuildRisalat, $risala->guild()->id()),
        );
    }
}
