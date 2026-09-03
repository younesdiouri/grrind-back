<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AnnounceRisala;
use App\Community\Application\AnnounceRisalaHandler;
use App\Community\Application\AnnounceRisalaTurn;
use App\Community\Application\AnnounceRisalaTurnHandler;
use App\Community\Application\RevealRisalat;
use App\Community\Application\RevealRisalatHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\PlayerLocales;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Locale;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\LocalHours;
use App\Tests\Support\SpyingPushSender;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les deux annonces des Risālāt (#194) : « c'est ton tour » à une personne, « la Risāla est
 * partie » à toute la guilde.
 *
 * **Les heures calmes ne traitent pas les deux pareil, et c'est le cœur du ticket.** La
 * révélation est une information durable : elle sera dans l'app au réveil, donc elle se perd
 * si la plage est ouverte. Le tour, lui, a une échéance — le manquer coûte une semaine à toute
 * la guilde — et 20h à Paris tombe à 3h du matin à Tokyo *toutes les semaines* : il se reporte.
 *
 * Le fuseau de chaque joueur est choisi pour qu'il y soit une heure donnée **au moment où la
 * suite tourne** ({@see LocalHours}) : c'est ce qui permet d'éprouver une règle horaire sans
 * que le test dépende de l'heure à laquelle on le lance.
 */
final class RisalatNotificationTest extends ApiTestCase
{
    use LocalHours;

    private const int AWAKE = 15;
    private const int ASLEEP = 2;

    private MockClock $clock;
    private RevealRisalatHandler $reveal;
    private AnnounceRisalaHandler $announceRisala;
    private AnnounceRisalaTurnHandler $announceTurn;
    private RisalaRepository $risalat;
    private GuildRepository $guilds;
    private CreditingDisciplines $crediting;
    private Guild $guild;

    protected function setUp(): void
    {
        parent::setUp();

        SpyingPushSender::forget();
    }

    public function testTheDrawnMemberIsToldItIsHisTurnAndNobodyElseIs(): void
    {
        $this->guildOfThree(self::AWAKE);
        $turn = $this->firstTurn();

        ($this->announceTurn)(new AnnounceRisalaTurn($turn->id()));

        // Un seul destinataire, et c'est celui que la rotation a désigné. Prévenir toute la
        // guilde qu'« un membre doit choisir » ne demanderait rien à personne en particulier.
        self::assertCount(1, SpyingPushSender::$sent);
        self::assertTrue(SpyingPushSender::$sent[0]['recipientId']->equals($turn->senderId()));

        $notification = SpyingPushSender::$sent[0]['notification'];
        self::assertSame(NotificationCategory::RisalaTurn, $notification->category);

        // Le tap ouvre l'écran des Risālāt de la guilde, pas un profil : la cible est la
        // guilde, et l'identifiant est une clé de ressource, jamais une donnée à afficher.
        self::assertSame(PushRouteType::GuildRisalat, $notification->route?->type);
        self::assertTrue($notification->route->targetId->equals($this->guild->id()));
    }

    public function testTheRevealReachesEveryMemberIncludingItsSender(): void
    {
        $members = $this->guildOfThree(self::AWAKE);
        $risala = $this->revealedRisala(Discipline::Climbing);

        ($this->announceRisala)(new AnnounceRisala($risala->id()));

        // L'expéditeur compris : il sait ce qu'il a choisi, mais pas *quand* ça partait, et
        // c'est le moment social de la semaine. L'exclure lui apprendrait qu'il ne fait plus
        // tout à fait partie de la guilde pendant deux secondes.
        self::assertSame(
            self::sortedIds(array_map(static fn (Account $member): Uuid => $member->id, $members)),
            self::sortedIds(array_column(SpyingPushSender::$sent, 'recipientId')),
        );

        self::assertSame(NotificationCategory::RisalaRevealed, SpyingPushSender::$sent[0]['notification']->category);
        self::assertStringContainsString('Climbing', SpyingPushSender::$sent[0]['notification']->body);
    }

    /** Une même annonce est rendue dans la langue stockée de chacun des membres. */
    public function testTheRevealUsesEachMembersPersistedLocale(): void
    {
        $members = $this->guildOfThree(self::AWAKE);
        self::assertSame(Response::HTTP_OK, $this->send('PATCH', '/api/me', ['locale' => 'fr'], $members[1]->headers)->getStatusCode());
        $risala = $this->revealedRisala(Discipline::Climbing);

        ($this->announceRisala)(new AnnounceRisala($risala->id()));

        $bodies = [];
        foreach (SpyingPushSender::$sent as $sent) {
            $bodies[$sent['recipientId']->toRfc4122()] = $sent['notification']->body;
        }

        self::assertStringContainsString('Escalade', $bodies[$members[1]->id->toRfc4122()]);
        self::assertStringContainsString('Climbing', $bodies[$members[2]->id->toRfc4122()]);
    }

    public function testAMemberInQuietHoursMissesTheReveal(): void
    {
        $members = $this->guildOfThree(self::AWAKE, asleep: 0);
        $risala = $this->revealedRisala(Discipline::Climbing);

        ($this->announceRisala)(new AnnounceRisala($risala->id()));

        // Perdue pour lui, et c'est le bon comportement : la Risāla sera dans l'app à son
        // réveil, avec ses quinze jours devant elle. Rien à reporter.
        self::assertCount(2, SpyingPushSender::$sent);
        self::assertNotContains(
            $members[0]->id->toRfc4122(),
            array_map(static fn (Uuid $id): string => $id->toRfc4122(), array_column(SpyingPushSender::$sent, 'recipientId')),
        );
    }

    /** **Le test qui porte le ticket** : ce qui a une échéance ne se perd pas dans la nuit. */
    public function testTheTurnIsPostponedRatherThanLostWhenItFallsAtNight(): void
    {
        $this->guildOfThree(self::ASLEEP);
        $turn = $this->firstTurn();

        $before = $this->queued();

        ($this->announceTurn)(new AnnounceRisalaTurn($turn->id()));

        // Rien n'est parti — on ne réveille personne à 3h du matin pour une échéance qui
        // court sur sept jours...
        self::assertSame([], SpyingPushSender::$sent);

        // ...mais le message est reprogrammé, pas abandonné. Sans ça, un joueur dont il fait
        // toujours nuit à l'heure de la bascule ne saurait jamais que c'est son tour, et sa
        // guilde perdrait une semaine à chaque rotation.
        self::assertSame($before + 1, $this->queued());
    }

    public function testAReplayedAnnouncementSendsNothingMore(): void
    {
        $this->guildOfThree(self::AWAKE);
        $risala = $this->revealedRisala(Discipline::Climbing);

        ($this->announceRisala)(new AnnounceRisala($risala->id()));
        ($this->announceRisala)(new AnnounceRisala($risala->id()));

        // L'outbox livre au moins une fois. C'est la réservation prise avant l'appel réseau
        // qui borne le nombre d'envois — pas l'espoir qu'un handler ne rejoue jamais.
        self::assertCount(3, SpyingPushSender::$sent);
    }

    public function testRevealLocalizationFailureDoesNotClaimTheNotificationBeforeRetry(): void
    {
        $this->guildOfThree(self::AWAKE);
        $risala = $this->revealedRisala(Discipline::Climbing);
        $locales = $this->flakyLocales();
        $handler = new AnnounceRisalaHandler(
            self::service(RisalaRepository::class),
            self::service(PlayerProfiles::class),
            $locales,
            self::service(PlayerTimezones::class),
            self::service(SpyingPushSender::class),
            self::service(NotificationAttemptRepository::class),
            self::service(\App\Community\Domain\QuietHours::class),
            self::service(\App\Community\Domain\RisalaRules::class),
            self::service(MockClock::class),
            self::service(TranslatorInterface::class),
            self::service(DisciplineTranslator::class),
        );

        try {
            $handler(new AnnounceRisala($risala->id()));
            self::fail('La première localisation devait échouer.');
        } catch (RuntimeException) {
        }
        $handler(new AnnounceRisala($risala->id()));

        self::assertCount(3, SpyingPushSender::$sent);
    }

    public function testTurnLocalizationFailureDoesNotClaimTheNotificationBeforeRetry(): void
    {
        $this->guildOfThree(self::AWAKE);
        $turn = $this->firstTurn();
        $locales = $this->flakyLocales();
        $handler = new AnnounceRisalaTurnHandler(
            self::service(RisalaRepository::class),
            self::service(PlayerTimezones::class),
            $locales,
            self::service(SpyingPushSender::class),
            self::service(NotificationAttemptRepository::class),
            self::service(\App\Community\Domain\QuietHours::class),
            self::service(MessageBusInterface::class),
            self::service(MockClock::class),
            self::service(TranslatorInterface::class),
        );

        try {
            $handler(new AnnounceRisalaTurn($turn->id()));
            self::fail('La première localisation devait échouer.');
        } catch (RuntimeException) {
        }
        $handler(new AnnounceRisalaTurn($turn->id()));

        self::assertCount(1, SpyingPushSender::$sent);
    }

    public function testTheBasculeQueuesBothAnnouncementsInItsOwnTransaction(): void
    {
        $this->guildOfThree(self::AWAKE);

        // Le premier tirage n'a rien à révéler : une seule annonce, celle du tour.
        $afterTheDraw = $this->queued();
        self::assertSame(1, $afterTheDraw);

        $this->chooseAndAdvance(Discipline::Climbing);

        // La bascule suivante révèle *et* tire : les deux annonces partent, écrites dans le
        // même `COMMIT` que la révélation elle-même.
        self::assertSame($afterTheDraw + 2, $this->queued());
    }

    public function testASealedTurnIsNoLongerAnnounced(): void
    {
        $this->guildOfThree(self::AWAKE);
        $turn = $this->firstTurn();

        // Le message a dormi jusqu'après l'échéance — un worker arrêté toute une semaine, ou
        // un report qui l'a traversée. Demander une action que le serveur refuse déjà serait
        // pire que se taire.
        $this->chooseAndAdvance(Discipline::Climbing);

        ($this->announceTurn)(new AnnounceRisalaTurn($turn->id()));

        self::assertSame([], SpyingPushSender::$sent);
    }

    /**
     * Trois membres, tous à `$localHour` chez eux, sauf celui d'indice `$asleep` s'il est
     * donné — celui-là est en pleine nuit.
     *
     * @return list<Account>
     */
    private function guildOfThree(int $localHour, ?int $asleep = null): array
    {
        $founder = $this->openAccount();
        $response = $this->post('/api/guilds', ['name' => 'Les Increvables'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);

        $members = [$founder];

        foreach (['baha' => 'Baha', 'carla' => 'Carla'] as $handle => $name) {
            $code = self::decode($this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers))['code'];
            self::assertIsString($code);

            $member = $this->openAccount($handle.'@grrind.app', $name);
            self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());

            $members[] = $member;
        }

        foreach ($members as $index => $member) {
            $hour = $index === $asleep ? self::ASLEEP : $localHour;
            self::assertSame(
                Response::HTTP_OK,
                $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo($hour)], $member->headers)->getStatusCode(),
            );
        }

        // Après le dernier appel HTTP : le `KernelBrowser` redémarre le noyau à chaque
        // requête, ce qui reconstruirait l'horloge à son instant de départ.
        $this->clock = self::service(MockClock::class);
        $this->reveal = self::service(RevealRisalatHandler::class);
        $this->announceRisala = self::service(AnnounceRisalaHandler::class);
        $this->announceTurn = self::service(AnnounceRisalaTurnHandler::class);
        $this->risalat = self::service(RisalaRepository::class);
        $this->guilds = self::service(GuildRepository::class);
        $this->crediting = self::service(CreditingDisciplines::class);

        $guild = $this->guilds->ofId(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);
        $this->guild = $guild;

        ($this->reveal)(new RevealRisalat());

        return $members;
    }

    private function firstTurn(): Risala
    {
        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);

        return $turn;
    }

    private function revealedRisala(Discipline $discipline): Risala
    {
        $this->chooseAndAdvance($discipline);

        $live = $this->risalat->liveIn($this->guild, $this->clock->now());
        self::assertCount(1, $live);

        return $live[0];
    }

    /** Choisit sur le tour ouvert, puis fait tomber son échéance. */
    private function chooseAndAdvance(Discipline $discipline): void
    {
        $turn = $this->firstTurn();
        $turn->choose($discipline, $this->crediting, [], $this->clock->now());
        $this->risalat->commit();

        $this->clock->sleep($turn->deadline()->getTimestamp() - $this->clock->now()->getTimestamp());

        ($this->reveal)(new RevealRisalat());
    }

    /** Tout ce que l'outbox porte, dû ou différé — c'est le report qu'on veut voir. */
    private function queued(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function flakyLocales(): PlayerLocales
    {
        return new class implements PlayerLocales {
            private bool $first = true;

            public function localeOf(Uuid $userId): Locale
            {
                if ($this->first) {
                    $this->first = false;
                    throw new RuntimeException('Catalogue de langues momentanément indisponible.');
                }

                return Locale::English;
            }
        };
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function sortedIds(array $ids): array
    {
        $values = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);
        sort($values);

        return $values;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $service
     *
     * @return T
     */
    private static function service(string $service): object
    {
        $instance = self::getContainer()->get($service);
        self::assertInstanceOf($service, $instance);

        return $instance;
    }
}
