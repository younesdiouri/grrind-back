<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildMembership;
use App\Community\Domain\QuietHours;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRules;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

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
        private PlayerTimezones $timezones,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private QuietHours $quietHours,
        private RisalaRules $rules,
        private ClockInterface $clock,
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

        $notification = new PushNotification(
            'Risāla de la semaine',
            \sprintf('📜 %s envoie %s à la guilde — +%d %% pendant deux semaines', $sender->displayName, self::disciplineLabel($discipline), $this->rules->recipientBonusPercent),
            NotificationCategory::RisalaRevealed,
            // Une seule Risāla par semaine et par guilde : la clé de regroupement est celle
            // de la guilde, donc l'annonce de la semaine remplace celle de la précédente sur
            // l'appareil plutôt que de s'empiler à côté.
            'risala:'.$risala->guild()->id()->toRfc4122(),
            new PushRoute(PushRouteType::GuildRisalat, $risala->guild()->id()),
        );

        $now = $this->clock->now();

        foreach ($risala->guild()->members() as $member) {
            $this->announceTo($member, $notification, $risala->id(), $now);
        }
    }

    private function announceTo(GuildMembership $member, PushNotification $notification, Uuid $risalaId, DateTimeImmutable $now): void
    {
        $recipientId = $member->playerId();

        // Les heures calmes appartiennent au destinataire, jamais à l'auteur ni au serveur
        // (#133) : 20h à Paris est 3h du matin à Tokyo, et l'annonce est durable — elle sera
        // dans l'app au réveil.
        if ($this->quietHours->contains($now, $this->timezones->of($recipientId))) {
            return;
        }

        if (!$this->attempts->claim($risalaId, $recipientId, $notification->category, $now)) {
            return;
        }

        $this->pushSender->send($recipientId, $notification);
    }

    /**
     * Une traduction en dur, pour la même raison qu'à {@see AnnounceGuildActivityHandler} :
     * c'est le texte d'un push, pas une donnée du contrat API, et un worker asynchrone n'a
     * pas de requête HTTP dont tirer une locale.
     */
    private static function disciplineLabel(Discipline $discipline): string
    {
        return match ($discipline) {
            Discipline::Running => 'la Course',
            Discipline::Walking => 'la Marche',
            Discipline::Cycling => 'le Vélo',
            Discipline::Swimming => 'la Natation',
            Discipline::Strength => 'la Musculation',
            Discipline::Hiit => 'le HIIT',
            Discipline::Hiking => 'la Randonnée',
            Discipline::Mobility => 'la Mobilité',
            Discipline::Climbing => 'l\'Escalade',
            Discipline::Football => 'le Football',
            Discipline::CourtSports => 'les Sports de terrain',
            Discipline::RacketSports => 'les Sports de raquette',
        };
    }
}
