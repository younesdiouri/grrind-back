<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AnnounceGuildActivity;
use App\Community\Application\AnnounceGuildActivityHandler;
use App\Community\Domain\PendingGuildActivity;
use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\WorkoutCreditedSpy;
use App\Tests\Support\SpyingPushSender;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `GuildActivityNotifier` (#133) : une séance créditée réveille la guilde, sans la noyer.
 *
 * {@see self::testABatchImportedAtOnceProducesOnePushPerRecipient()} est le test qui dit si
 * le ticket est fait — c'est celui que la description du ticket met en avant. Les autres
 * couvrent chacune des règles qui l'y amènent : fraîcheur, agrégation, heures calmes,
 * l'absence de destinataire quand l'auteur n'a pas de guilde, et le mode dégradé
 * documenté dans `AnnounceGuildActivity`.
 *
 * **`AnnounceGuildActivity` est toujours dispatchée avec un `DelayStamp`** (voir son
 * docblock), donc `consumeTheOutbox()` ne l'atteint jamais : `messenger:consume` ne sert
 * que les messages dont `available_at` est déjà passé. Chaque test qui a besoin de voir
 * l'annonce partir appelle `flushPendingAnnouncement()`, qui invoque directement
 * `AnnounceGuildActivityHandler` — public en environnement `test` pour ça, voir
 * `config/services.yaml`. C'est aussi ce qui permet de simuler le mode dégradé : appeler
 * ce handler *entre* deux séances créditées du même auteur revient à traiter l'annonce
 * comme si le délai s'était déjà écoulé.
 */
final class GuildActivityNotifierTest extends ApiTestCase
{
    /**
     * Un fuseau IANA réel sans heure d'été par décalage UTC entier, pour
     * {@see self::timezoneShiftingUtcHourTo()}.
     *
     * @var array<int, string>
     */
    private const array ZONE_BY_UTC_OFFSET = [
        0 => 'UTC',
        1 => 'Africa/Lagos',
        2 => 'Africa/Johannesburg',
        3 => 'Africa/Nairobi',
        4 => 'Asia/Dubai',
        5 => 'Asia/Karachi',
        6 => 'Asia/Dhaka',
        7 => 'Asia/Bangkok',
        8 => 'Asia/Shanghai',
        9 => 'Asia/Tokyo',
        10 => 'Australia/Brisbane',
        11 => 'Pacific/Noumea',
        12 => 'Pacific/Wallis',
        13 => 'Pacific/Tongatapu',
        14 => 'Pacific/Kiritimati',
        -1 => 'Atlantic/Cape_Verde',
        -2 => 'America/Noronha',
        -3 => 'America/Sao_Paulo',
        -4 => 'America/La_Paz',
        -5 => 'America/Bogota',
        -6 => 'America/Guatemala',
        -7 => 'America/Phoenix',
        -8 => 'Pacific/Pitcairn',
        -9 => 'Pacific/Gambier',
        -10 => 'Pacific/Honolulu',
        -11 => 'Pacific/Pago_Pago',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        WorkoutCreditedSpy::forget();
        SpyingPushSender::forget();
    }

    /**
     * Le test du ticket : un joueur qui rentre après une absence synchronise plusieurs
     * séances d'un coup, toutes fraîches. Sa guilde ne reçoit qu'**une** annonce chacune,
     * pas une par séance — trois séances créditées, jamais trois pushes.
     */
    public function testABatchImportedAtOnceProducesOnePushPerRecipient(): void
    {
        [$author, $recipients] = $this->guildOfFour();

        $response = $this->import($author, [
            self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 110),
            self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 70),
            self::freshCandidate(externalId: 'HK-3', endedMinutesAgo: 30),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->consumeTheOutbox();

        self::assertCount(3, WorkoutCreditedSpy::$received, 'Les trois séances doivent avoir été créditées pour que le test prouve quelque chose.');
        $totalXp = array_sum(array_map(static fn ($event) => $event->xpGranted, WorkoutCreditedSpy::$received));

        $this->flushPendingAnnouncement($author);

        self::assertCount(\count($recipients), SpyingPushSender::$sent, 'Un push par destinataire, pas un par séance : trois séances créditées ne doivent produire ni zéro ni neuf envois.');

        $recipientIds = array_map(static fn (Account $account): string => $account->id->toRfc4122(), $recipients);

        foreach (SpyingPushSender::$sent as $sent) {
            self::assertContains($sent['recipientId']->toRfc4122(), $recipientIds, 'Aucun push ne doit viser l\'auteur lui-même ni un joueur d\'une autre guilde.');
            self::assertSame('GUILD_ACTIVITY', $sent['notification']->category->value);
            self::assertStringContainsString('3 séances', $sent['notification']->body);
            self::assertStringContainsString('+'.$totalXp.' XP', $sent['notification']->body);

            // #144 : le tap doit mener au profil de l'auteur de la séance, pas à celui du
            // destinataire — c'est GET /api/players/{id} qui le résout ensuite.
            self::assertSame(PushRouteType::PlayerProfile, $sent['notification']->route->type);
            self::assertTrue($sent['notification']->route->targetId->equals($author->id));
        }

        // Un seul destinataire par identifiant : la boucle ci-dessus n'a pas pu compter
        // deux fois le même joueur pour se donner l'illusion d'un seul push chacun.
        $notifiedIds = array_map(static fn (array $sent): string => $sent['recipientId']->toRfc4122(), SpyingPushSender::$sent);
        self::assertCount(\count($recipients), array_unique($notifiedIds));
    }

    /** Le second cas du même test : tout le lot est ancien, personne n'est notifié. */
    public function testABatchOfOldSessionsProducesNoPush(): void
    {
        [$author] = $this->guildOfFour();

        $this->import($author, [
            self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 200),
            self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 300),
        ]);

        $this->consumeTheOutbox();

        self::assertCount(2, WorkoutCreditedSpy::$received, 'Un workout ancien reste crédité : seule l\'annonce est retenue, pas l\'XP.');
        self::assertCount(0, SpyingPushSender::$sent);
    }

    /**
     * Une seule séance créditée porte le message détaillé — discipline, durée, XP — plutôt
     * que la forme agrégée réservée à plusieurs séances.
     */
    public function testASingleFreshSessionUsesTheDetailedMessage(): void
    {
        [$author] = $this->guildOfFour();

        $this->import($author, [self::freshCandidate(durationSeconds: 2700)]);

        $this->consumeTheOutbox();

        self::assertCount(1, WorkoutCreditedSpy::$received);
        $xp = WorkoutCreditedSpy::$received[0]->xpGranted;

        $this->flushPendingAnnouncement($author);

        self::assertNotCount(0, SpyingPushSender::$sent);

        foreach (SpyingPushSender::$sent as $sent) {
            self::assertStringContainsString('45 min de course', $sent['notification']->body);
            self::assertStringContainsString('+'.$xp.' XP', $sent['notification']->body);
        }
    }

    /** Pas de guilde, pas de destinataire — et surtout, aucune erreur. */
    public function testAPlayerWithoutAGuildNotifiesNoOne(): void
    {
        $solo = $this->openAccount('solo@grrind.app', 'Solo');

        $response = $this->import($solo, [self::freshCandidate()]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->consumeTheOutbox();

        self::assertCount(1, WorkoutCreditedSpy::$received);
        self::assertCount(0, SpyingPushSender::$sent);
    }

    /**
     * Les heures calmes se lisent dans le fuseau du **destinataire** : le même événement
     * réveille l'un et laisse l'autre tranquille.
     */
    public function testQuietHoursAreEvaluatedPerRecipient(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');
        $guildId = $this->foundGuild($author);

        $awake = $this->openAccount('awake@grrind.app', 'Awake');
        $asleep = $this->openAccount('asleep@grrind.app', 'Asleep');

        $this->join($awake, $this->issueCode($author, $guildId));
        $this->join($asleep, $this->issueCode($author, $guildId));

        // Heures calmes : 22h-8h (notifications.yaml). Le fuseau de chacun est choisi à
        // l'instant du test pour placer son heure locale loin des deux bornes — 15h pour
        // l'un, 2h du matin pour l'autre — plutôt que de figer deux fuseaux dont l'écart
        // avec « maintenant » varie avec le jour où le test tourne.
        $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(15)], $awake->headers);
        $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(2)], $asleep->headers);

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();
        $this->flushPendingAnnouncement($author);

        $notifiedIds = array_map(static fn (array $sent): string => $sent['recipientId']->toRfc4122(), SpyingPushSender::$sent);

        self::assertContains($awake->id->toRfc4122(), $notifiedIds);
        self::assertNotContains($asleep->id->toRfc4122(), $notifiedIds);
    }

    /**
     * Le mode dégradé documenté dans le docblock d'`AnnounceGuildActivity`, plutôt qu'un
     * mode qu'on prétendrait ne jamais exister : si l'annonce est traitée *entre* deux
     * séances créditées du même auteur — ici simulé en appelant
     * `AnnounceGuildActivityHandler` directement, comme le ferait un worker dont le
     * `DelayStamp` s'est déjà écoulé — la seconde séance rouvre une fenêtre et une
     * seconde annonce part. Deux pushes, pas un — dégradé, pas corrompu : aucune séance
     * n'est perdue, aucun destinataire n'est notifié à tort.
     */
    public function testAnAnnouncementFlushedBetweenTwoSessionsProducesTwoAnnouncements(): void
    {
        [$author, $recipients] = $this->guildOfFour();

        // Décalées pour ne pas se chevaucher — Training écarterait sinon la seconde comme
        // le même effort que la première, et le test ne démontrerait plus rien du #133.
        $this->import($author, [self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 90)]);
        $this->consumeTheOutbox();
        $this->flushPendingAnnouncement($author);

        self::assertCount(\count($recipients), SpyingPushSender::$sent, 'La première annonce doit partir avant que le test en démontre une seconde.');

        $this->import($author, [self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 5)], key: 'import-suivant');
        $this->consumeTheOutbox();
        $this->flushPendingAnnouncement($author);

        self::assertCount(
            2 * \count($recipients),
            SpyingPushSender::$sent,
            'La seconde séance, créditée après que la première annonce est partie, rouvre une fenêtre : c\'est le mode dégradé, documenté et non silencieux.',
        );
    }

    /**
     * Le #134 : un rejeu du même message après un traitement déjà complet ne renvoie rien
     * — la fenêtre a été refermée par la première exécution, `activityFor()` la trouve
     * absente et le handler ressort sans y toucher. C'est le cas nominal du « au moins une
     * fois » de l'outbox : l'accusé de réception se perd, le message revient, personne ne
     * doit rien recevoir une seconde fois.
     */
    public function testAReplayedAnnouncementAfterFullSuccessSendsNothingAgain(): void
    {
        [$author, $recipients] = $this->guildOfFour();

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();

        $windowId = $this->flushPendingAnnouncement($author);
        self::assertCount(\count($recipients), SpyingPushSender::$sent, 'La première exécution doit avoir notifié tout le monde pour que le rejeu démontre quelque chose.');

        $this->announce($author, $windowId);

        self::assertCount(\count($recipients), SpyingPushSender::$sent, 'Le rejeu du même message ne doit ajouter aucun envoi : la fenêtre est déjà refermée.');
    }

    /**
     * Le cas que le #134 cible vraiment : une panne *au milieu* de la boucle d'envoi, pas
     * après. Un destinataire porte déjà une réservation d'envoi — comme s'il avait reçu son
     * push avant que le worker ne tombe — et le rejeu du même message ne doit renvoyer
     * qu'aux deux autres, jamais à lui.
     */
    public function testARetriedAnnouncementSkipsAnAlreadyDeliveredRecipientAndReachesTheOthers(): void
    {
        [$author, $recipients] = $this->guildOfFour();

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();

        $windowId = $this->currentWindowId($author);
        $alreadyNotified = $recipients[0];

        $attempts = self::getContainer()->get(NotificationAttemptRepository::class);
        self::assertInstanceOf(NotificationAttemptRepository::class, $attempts);
        self::assertTrue($attempts->claim($windowId, $alreadyNotified->id, NotificationCategory::GuildActivity, new DateTimeImmutable()), 'La réservation doit réussir la première fois — c\'est ce qui simule l\'envoi déjà parti avant la panne.');

        $this->announce($author, $windowId);

        $notifiedIds = array_map(static fn (array $sent): string => $sent['recipientId']->toRfc4122(), SpyingPushSender::$sent);

        self::assertCount(2, SpyingPushSender::$sent, 'Le destinataire déjà tracé ne doit pas recevoir de second envoi, mais les deux autres doivent recevoir le leur malgré la panne simulée.');
        self::assertNotContains($alreadyNotified->id->toRfc4122(), $notifiedIds, 'Ce destinataire a « déjà reçu » son push avant la panne simulée : le rejeu ne doit pas le renotifier.');
    }

    /**
     * Le scénario que la revue du #134 a mis en évidence : un handler qui épuise ses trois
     * tentatives (`messenger.yaml`) laisse la fenêtre ouverte pour de bon, puisque `close()`
     * ne se déclenche plus qu'en sortie normale. Sans `stale_window_minutes`, plus aucune
     * séance suivante de cet auteur ne redéclencherait d'annonce — la guilde deviendrait
     * muette en silence. Une fenêtre vieillie au-delà du seuil doit donc repartir, sur le
     * même `windowId`, et sans renotifier qui que ce soit déjà servi avant l'abandon.
     */
    public function testAnAbandonedWindowReopensWithoutDoublyNotifyingAlreadyServedRecipients(): void
    {
        [$author, $recipients] = $this->guildOfFour();

        $this->import($author, [self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 90)]);
        $this->consumeTheOutbox();

        $windowId = $this->currentWindowId($author);
        $alreadyNotified = $recipients[0];

        // Comme si le worker était mort après avoir notifié ce seul destinataire, sur les
        // trois tentatives permises — le message est parti sur le transport `failed`, plus
        // personne ne le rejoue, et la fenêtre reste ouverte sans que `close()` n'ait jamais
        // été appelée.
        $attempts = self::getContainer()->get(NotificationAttemptRepository::class);
        self::assertInstanceOf(NotificationAttemptRepository::class, $attempts);
        self::assertTrue($attempts->claim($windowId, $alreadyNotified->id, NotificationCategory::GuildActivity, new DateTimeImmutable()));

        // `stale_window_minutes` (notifications.yaml) vaut quinze minutes : on recule
        // `opened_at` bien au-delà pour que `recordSession()` traite la fenêtre comme
        // abandonnée plutôt que comme une annonce encore en vol.
        $this->connection()->executeStatement(
            "UPDATE community_pending_guild_activity SET opened_at = NOW() - INTERVAL '20 minutes' WHERE author_id = :authorId",
            ['authorId' => $author->id->toRfc4122()],
        );

        $this->import($author, [self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 5)], key: 'import-suivant');
        $this->consumeTheOutbox();

        self::assertSame(
            $windowId->toRfc4122(),
            $this->currentWindowId($author)->toRfc4122(),
            'Une fenêtre abandonnée se réutilise, elle n\'en ouvre pas une seconde : c\'est ce qui garde la trace de livraison déjà écrite valide pour la suite.',
        );

        $this->flushPendingAnnouncement($author);

        $notifiedIds = array_map(static fn (array $sent): string => $sent['recipientId']->toRfc4122(), SpyingPushSender::$sent);

        self::assertCount(2, SpyingPushSender::$sent, 'La fenêtre abandonnée doit repartir vers les deux destinataires jamais servis.');
        self::assertNotContains($alreadyNotified->id->toRfc4122(), $notifiedIds, 'Celui déjà notifié avant l\'abandon ne doit pas recevoir de second push.');
    }

    /**
     * @return array{0: Account, 1: list<Account>, 2: string}
     */
    private function guildOfFour(): array
    {
        $author = $this->openAccount('author@grrind.app', 'Author');
        $guildId = $this->foundGuild($author);

        $recipients = [];

        foreach (['margot@grrind.app', 'noe@grrind.app', 'lea@grrind.app'] as $index => $email) {
            $recipient = $this->openAccount($email, 'Membre'.$index);
            $this->join($recipient, $this->issueCode($author, $guildId));
            $recipients[] = $recipient;
        }

        return [$author, $recipients, $guildId];
    }

    private function foundGuild(Account $founder, string $name = 'Les Increvables'): string
    {
        $response = $this->post('/api/guilds', ['name' => $name], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $id = self::decode($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function issueCode(Account $founder, string $guildId): string
    {
        $response = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $code = self::decode($response)['code'];
        self::assertIsString($code);

        return $code;
    }

    private function join(Account $player, string $code): Response
    {
        $response = $this->post('/api/guilds/join', ['code' => $code], $player->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts, string $key = 'import-du-jour'): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    /**
     * Le fuseau dont l'heure locale vaut `$targetLocalHour` **à l'instant où le test
     * tourne** — pas un fuseau fixé une fois pour toutes, dont l'écart avec l'heure réelle
     * changerait selon le jour de l'exécution et rendrait ce test dépendant de l'heure à
     * laquelle la suite passe.
     */
    private static function timezoneShiftingUtcHourTo(int $targetLocalHour): string
    {
        $utcHour = (int) new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('G');
        $offset = ($targetLocalHour - $utcHour + 24) % 24;

        if ($offset > 14) {
            $offset -= 24;
        }

        return self::ZONE_BY_UTC_OFFSET[$offset];
    }

    /**
     * @return array<string, mixed>
     */
    private static function freshCandidate(
        string $externalId = 'HK-001',
        int $endedMinutesAgo = 5,
        int $durationSeconds = 1800,
    ): array {
        $endedAt = new DateTimeImmutable(\sprintf('-%d minutes', $endedMinutesAgo));
        $startedAt = $endedAt->modify(\sprintf('-%d seconds', $durationSeconds));

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => 'running',
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $endedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Invoque `AnnounceGuildActivityHandler` directement, comme si le `DelayStamp` posé
     * par `GuildActivityNotifier` s'était déjà écoulé — l'outbox ne le sert jamais avant
     * ça, donc `consumeTheOutbox()` seule ne suffit pas à observer un envoi.
     *
     * Rend le `windowId` traité, pour les tests d'idempotence du #134 qui ont besoin de le
     * rejouer explicitement — la fenêtre étant refermée en sortie de handler, on ne peut
     * plus le relire depuis la base après coup.
     */
    private function flushPendingAnnouncement(Account $author): Uuid
    {
        $windowId = $this->currentWindowId($author);
        $this->announce($author, $windowId);

        return $windowId;
    }

    /** Le `windowId` de la fenêtre actuellement ouverte pour cet auteur. */
    private function currentWindowId(Account $author): Uuid
    {
        $repository = self::getContainer()->get(PendingGuildActivityRepository::class);
        self::assertInstanceOf(PendingGuildActivityRepository::class, $repository);

        $pending = $repository->find($author->id);
        self::assertInstanceOf(PendingGuildActivity::class, $pending, 'Aucune fenêtre ouverte pour cet auteur : le test qui appelle ceci a-t-il bien crédité une séance fraîche avant ?');

        return $pending->windowId();
    }

    /** Rejoue `AnnounceGuildActivityHandler` pour un `(authorId, windowId)` précis, sans relire l'état courant — c'est le point du test de rejeu. */
    private function announce(Account $author, Uuid $windowId): void
    {
        $handler = self::getContainer()->get(AnnounceGuildActivityHandler::class);
        self::assertInstanceOf(AnnounceGuildActivityHandler::class, $handler);

        $handler(new AnnounceGuildActivity($author->id, $windowId));
    }

    /**
     * Draine l'outbox de tout ce qui est déjà dû, y compris ce qu'un message en cours de
     * traitement y ajoute d'immédiatement disponible — `WorkoutImported` et
     * `WorkoutCredited` partagent la même file. `AnnounceGuildActivity`, elle, porte
     * toujours un `DelayStamp` : cette boucle ne l'atteint jamais, voir
     * `flushPendingAnnouncement()`.
     */
    private function consumeTheOutbox(): void
    {
        while (($pending = $this->outboxSize()) > 0) {
            $application = new Application(self::bootedKernel());
            $application->setAutoExit(false);

            $tester = new CommandTester($application->find('messenger:consume'));
            $status = $tester->execute([
                'receivers' => ['outbox'],
                '--limit' => $pending,
                '--time-limit' => 10,
            ]);

            self::assertSame(0, $status, $tester->getDisplay());
        }
    }

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    /**
     * Seulement ce qui est **déjà dû** : un message retardé par un `DelayStamp` reste une
     * ligne de la table sans être un travail que `messenger:consume` sait servir
     * maintenant, et le compter ferait boucler `consumeTheOutbox()` jusqu'à son
     * `--time-limit` pour rien.
     */
    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE available_at <= NOW()');
        self::assertIsNumeric($pending);

        return (int) $pending;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
