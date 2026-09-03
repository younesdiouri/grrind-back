<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\QuietHours;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaStatus;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\PlayerLocales;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « C'est ton tour, choisis avant l'échéance » — à la seule personne que la rotation a tirée.
 *
 * ## Le report, qui est tout l'intérêt de ce handler
 *
 * Les heures calmes appartiennent au destinataire (#133), et cette règle ne bouge pas. Mais 20h
 * à Paris tombe à 3h du matin à Tokyo **toutes les semaines** : appliquer ici le traitement des
 * annonces d'activité — abandonner — condamnerait un joueur lointain à ne jamais apprendre que
 * c'est son tour, et donc à faire perdre une semaine à sa guilde à chaque rotation.
 *
 * Alors le message se redispatche à la sortie de la plage. Un seul saut, jamais une boucle : le
 * délai vise l'instant où {@see QuietHours::contains()} devient faux, donc la seconde passe
 * envoie. Et si elle n'envoyait pas — un fuseau changé entre-temps — la réservation
 * {@see NotificationAttemptRepository::claim()} bornerait quand même le nombre d'envois à un.
 *
 * ## Ce qui fait taire ce handler, et ce qui ne le fait pas
 *
 * Le tour n'est plus ouvert : il a été scellé pendant que le message dormait (le worker était
 * arrêté toute une semaine, ou le report a traversé l'échéance). Prévenir alors serait pire que
 * se taire — on demanderait une action que le serveur refuse déjà.
 *
 * En revanche, un tour dont le porteur a déjà choisi **est** notifié : le message ne dort qu'au
 * plus quelques heures, et le cas ne se produit que si le joueur a ouvert l'app entre-temps.
 * Lui rappeler qu'il a la main jusqu'à dimanche n'est pas une erreur — il peut encore changer
 * d'avis.
 */
final readonly class AnnounceRisalaTurnHandler
{
    /** Une minute après la sortie des heures calmes : la borne est stricte, le décalage évite d'y retomber. */
    private const int MARGIN_SECONDS = 60;

    public function __construct(
        private RisalaRepository $risalat,
        private PlayerTimezones $timezones,
        private PlayerLocales $locales,
        private PushSender $pushSender,
        private NotificationAttemptRepository $attempts,
        private QuietHours $quietHours,
        // Sans `#[Target]`, volontairement (#155) : ce message n'est pas un `DomainEvent`, il
        // doit rester sur le bus strict — celui que `MessageBusInterface` résout par défaut.
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceRisalaTurn $message): void
    {
        $risala = $this->risalat->find($message->risalaId);

        if (!$risala instanceof Risala) {
            return;
        }

        // Scellé pendant que le message dormait : demander une action que le serveur refuse
        // déjà serait pire que se taire.
        if (RisalaStatus::Drawn !== $risala->status()) {
            return;
        }

        $now = $this->clock->now();
        $timezone = $this->timezones->of($risala->senderId());

        if ($this->quietHours->contains($now, $timezone)) {
            $wakeUp = $this->quietHours->endsAfter($now, $timezone);

            $this->bus->dispatch($message, [new DelayStamp((($wakeUp->getTimestamp() - $now->getTimestamp()) + self::MARGIN_SECONDS) * 1000)]);

            return;
        }

        if (!$this->attempts->claim($risala->id(), $risala->senderId(), NotificationCategory::RisalaTurn, $now)) {
            return;
        }

        $locale = $this->locales->localeOf($risala->senderId())->value;
        $deadline = $risala->deadline()->setTimezone($timezone->toDateTimeZone())->format('fr' === $locale ? 'd/m \à H\hi' : 'M j \a\t H:i');

        $this->pushSender->send($risala->senderId(), new PushNotification(
            $this->translator->trans('risala_turn.title', domain: 'messages', locale: $locale),
            $this->translator->trans('risala_turn.body', ['%deadline%' => $deadline], 'messages', $locale),
            NotificationCategory::RisalaTurn,
            'risala-turn:'.$risala->guild()->id()->toRfc4122(),
            new PushRoute(PushRouteType::GuildRisalat, $risala->guild()->id()),
        ));
    }
}
