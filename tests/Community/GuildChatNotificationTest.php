<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AnnounceGuildMessage;
use App\Community\Application\AnnounceGuildMessageHandler;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\LocalHours;
use App\Tests\Support\SpyingPushSender;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le push d'un message de chat (#281).
 *
 * **Aucun `DelayStamp` ici**, contrairement à `AnnounceGuildActivity` : `consumeTheOutbox()`
 * suffit à voir l'annonce partir, aucun handler n'a besoin d'être appelé à la main.
 */
final class GuildChatNotificationTest extends ApiTestCase
{
    use LocalHours;

    protected function setUp(): void
    {
        parent::setUp();
        SpyingPushSender::forget();
    }

    /**
     * Le test du ticket : un message écrit part vers chaque co-équipier, **jamais vers son
     * auteur**, et il porte ce qui a été écrit — une notification qui dirait seulement
     * « nouveau message » obligerait à ouvrir l'app pour savoir si ça valait la peine.
     */
    public function testEveryMemberButTheAuthorGetsTheMessageAndItsText(): void
    {
        [$author, $members, $url, $guildId] = $this->guildOfThree();

        self::assertSame(Response::HTTP_CREATED, $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'On se retrouve à 18h'], $author->headers)->getStatusCode());
        $this->consumeTheOutbox();

        self::assertCount(\count($members), SpyingPushSender::$sent, 'Un push par membre sauf l\'auteur.');

        $recipientIds = array_map(static fn (Account $account): string => $account->id->toRfc4122(), $members);

        foreach (SpyingPushSender::$sent as $sent) {
            self::assertContains($sent['recipientId']->toRfc4122(), $recipientIds, 'L\'auteur ne se notifie jamais lui-même.');
            $notification = $sent['notification'];
            self::assertSame(NotificationCategory::GuildChat, $notification->category);
            self::assertSame('Les Bavards', $notification->title, 'Le titre nomme la conversation, pas le type d\'événement.');
            self::assertStringContainsString('Author', $notification->body);
            self::assertStringContainsString('On se retrouve à 18h', $notification->body);
            // Le fil, et non l'auteur : deux personnes qui se répondent ne doivent pas
            // empiler deux bandeaux dans le centre de notifications.
            self::assertSame('guild-chat:'.$guildId, $notification->groupingKey);
            self::assertSame(PushRouteType::GuildChat, $notification->route->type);
            self::assertSame($guildId, $notification->route->targetId->toRfc4122());
        }
    }

    /**
     * Les heures calmes se lisent dans le fuseau du **destinataire**, et ce qu'elles
     * écartent est perdu : un message a déjà sa destination durable, le chat.
     */
    public function testQuietHoursSilenceOneRecipientWithoutDeferringAnything(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');
        $guildId = $this->foundGuild($author);
        $awake = $this->openAccount('awake@grrind.app', 'Awake');
        $asleep = $this->openAccount('asleep@grrind.app', 'Asleep');
        $this->join($awake, $this->issueCode($author, $guildId));
        $this->join($asleep, $this->issueCode($author, $guildId));
        $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(15)], $awake->headers);
        $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(2)], $asleep->headers);

        $this->post('/api/guilds/'.$guildId.'/chat/messages', ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Nuit blanche'], $author->headers);
        $this->consumeTheOutbox();

        self::assertCount(1, SpyingPushSender::$sent);
        self::assertSame($awake->id->toRfc4122(), SpyingPushSender::$sent[0]['recipientId']->toRfc4122());
        self::assertSame(0, $this->outboxSize(), 'Rien ne reste en file : le message écarté ne se reporte pas au réveil.');
    }

    /**
     * L'outbox livre **au moins** une fois. C'est la réservation posée sur l'identifiant du
     * message — pas l'espoir que le handler ne rejoue jamais — qui empêche un second envoi.
     */
    public function testAReplayOfTheSameMessageNotifiesNobodyTwice(): void
    {
        [$author, $members, $url] = $this->guildOfThree();

        $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Une seule fois'], $author->headers);
        $this->consumeTheOutbox();
        self::assertCount(\count($members), SpyingPushSender::$sent);

        $this->replayTheLastAnnouncement();

        self::assertCount(\count($members), SpyingPushSender::$sent, 'Un rejeu ne doit renotifier personne.');
    }

    /** Un message sans texte — une image seule — a son propre corps, jamais « Author :  ». */
    public function testAnImageOnlyMessageAnnouncesItself(): void
    {
        [$author, , $url] = $this->guildOfThree();

        $path = tempnam(sys_get_temp_dir(), 'chat-push-');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagepng($image, $path);
        try {
            $this->client->request('POST', $url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => ''], ['image' => new UploadedFile($path, 'photo.png', 'image/png', test: true)], ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => $author->headers['Authorization']]);
            self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        } finally {
            unlink($path);
        }
        $this->consumeTheOutbox();

        self::assertNotSame([], SpyingPushSender::$sent);
        self::assertStringContainsString('image', SpyingPushSender::$sent[0]['notification']->body);
    }

    /**
     * Un pavé ne part pas entier dans une charge utile de push. Il est coupé sur un espace
     * et rendu avec des points de suspension.
     */
    public function testALongMessageIsShortenedOnAWordBoundary(): void
    {
        [$author, , $url] = $this->guildOfThree();
        // Des mots numérotés plutôt qu'un mot répété : c'est ce qui rend la coupe
        // observable — « phrase12… » prouve un mot entier, « phras… » l'aurait démenti.
        $long = implode(' ', array_map(static fn (int $index): string => 'phrase'.$index, range(1, 40)));

        $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => $long], $author->headers);
        $this->consumeTheOutbox();

        self::assertNotSame([], SpyingPushSender::$sent);
        $body = SpyingPushSender::$sent[0]['notification']->body;
        self::assertLessThan(mb_strlen($long), mb_strlen($body));
        self::assertStringContainsString('phrase1 phrase2', $body, 'Le début du message est bien celui qui part.');
        self::assertMatchesRegularExpression('/phrase\d+…$/u', $body, 'La coupe tombe entre deux mots, pas au milieu de l\'un d\'eux.');
    }

    /** @return array{Account, list<Account>, string, string} */
    private function guildOfThree(): array
    {
        $author = $this->openAccount('author@grrind.app', 'Author');
        $guildId = $this->foundGuild($author);
        $members = [];
        foreach (['margot@grrind.app', 'noe@grrind.app'] as $index => $email) {
            $member = $this->openAccount($email, 'Membre'.$index);
            $this->join($member, $this->issueCode($author, $guildId));
            // Quinze heures chez eux : les heures calmes ne doivent pas décider à la place
            // du test, quelle que soit l'heure à laquelle la suite tourne.
            $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(15)], $member->headers);
            $members[] = $member;
        }

        return [$author, $members, '/api/guilds/'.$guildId.'/chat/messages', $guildId];
    }

    private function foundGuild(Account $founder): string
    {
        $response = $this->post('/api/guilds', ['name' => 'Les Bavards'], $founder->headers);
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

    private function join(Account $player, string $code): void
    {
        self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $code], $player->headers)->getStatusCode());
    }

    /**
     * Remet en file l'annonce déjà consommée, pour éprouver le « au moins une fois » de
     * l'outbox sans provoquer une vraie panne au milieu de la boucle d'envoi.
     */
    private function replayTheLastAnnouncement(): void
    {
        $id = $this->connection()->fetchOne('SELECT id FROM community_guild_message ORDER BY position DESC LIMIT 1');
        self::assertIsString($id);

        $handler = self::bootedKernel()->getContainer()->get(AnnounceGuildMessageHandler::class);
        self::assertInstanceOf(AnnounceGuildMessageHandler::class, $handler);
        $handler(new AnnounceGuildMessage($id));
    }

    private function outboxSize(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NULL', ['queue' => 'default']);
        self::assertTrue(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::bootedKernel()->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    private function consumeTheOutbox(): void
    {
        while (($pending = $this->outboxSize()) > 0) {
            $application = new Application(self::bootedKernel());
            $application->setAutoExit(false);
            $tester = new CommandTester($application->find('messenger:consume'));
            self::assertSame(0, $tester->execute(['receivers' => ['outbox'], '--limit' => $pending, '--time-limit' => 10]), $tester->getDisplay());
        }
    }
}
