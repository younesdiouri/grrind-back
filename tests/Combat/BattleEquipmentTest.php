<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class BattleEquipmentTest extends ApiTestCase
{
    public function testPveFreezesOnlyWornItemsAndOldBattlesStayReadable(): void
    {
        $player = $this->openAccount();
        $bare = $this->fight($player);
        self::assertSame([], self::equipmentOf($bare, 'player'));
        self::assertSame([], self::equipmentOf($bare, 'enemy'));

        $this->grant($player, 'PURSUIT_GOGGLES');
        $this->grant($player, 'CROWN_OF_THE_TIRELESS');
        $this->equip($player, 'PURSUIT_GOGGLES');
        $dressed = $this->fight($player);
        self::assertSame(['HEAD' => ['key' => 'PURSUIT_GOGGLES', 'artworkVersion' => 'v4']], self::equipmentOf($dressed, 'player'));

        $dressedId = $dressed['id'];
        self::assertIsString($dressedId);
        $this->equip($player, 'CROWN_OF_THE_TIRELESS');
        $replaced = $this->fight($player);
        self::assertSame(['HEAD' => ['key' => 'CROWN_OF_THE_TIRELESS', 'artworkVersion' => null]], self::equipmentOf($replaced, 'player'));
        self::assertEquals($dressed, self::decode($this->get('/api/battles/'.$dressedId, $player->headers)));

        self::assertSame(Response::HTTP_OK, $this->send('DELETE', '/api/inventory/equipment/HEAD', null, $player->headers)->getStatusCode());
        self::assertSame([], self::equipmentOf($this->fight($player), 'player'));

        // Une vraie ancienne ligne JSONB, sans référence : aucune relecture de l'inventaire.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("UPDATE combat_battle SET player_snapshot = player_snapshot - 'equipment' WHERE id = :id", ['id' => $dressedId]);
        self::assertSame([], self::equipmentOf(self::decode($this->get('/api/battles/'.$dressedId, $player->headers)), 'player'));
    }

    public function testBothPvpOutfitsSurviveReplacementRemovalAndIdempotentReplay(): void
    {
        $player = $this->openAccount();
        $opponent = $this->openAccount('carla@grrind.app', 'Carla');
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Les coiffes'], $player->headers));
        $guildId = $guild['id'];
        self::assertIsString($guildId);
        $invite = self::decode($this->post('/api/guilds/'.$guildId.'/invite-code', [], $player->headers));
        self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $invite['code']], $opponent->headers)->getStatusCode());
        foreach ([$player, $opponent] as $account) {
            $this->grant($account, 'PURSUIT_GOGGLES');
            $this->equip($account, 'PURSUIT_GOGGLES');
        }
        $path = '/api/players/'.$opponent->id->toRfc4122().'/battles';
        $headers = $player->headers + ['Idempotency-Key' => 'duel-equipe'];
        $response = $this->post($path, [], $headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $fought = self::decode($response);
        $foughtId = $fought['id'];
        self::assertIsString($foughtId);
        $head = ['HEAD' => ['key' => 'PURSUIT_GOGGLES', 'artworkVersion' => 'v4']];
        self::assertSame($head, self::equipmentOf($fought, 'player'));
        self::assertSame($head, self::equipmentOf($fought, 'enemy'));

        $this->send('DELETE', '/api/inventory/equipment/HEAD', null, $player->headers);
        $this->grant($opponent, 'CROWN_OF_THE_TIRELESS');
        $this->equip($opponent, 'CROWN_OF_THE_TIRELESS');
        self::assertEquals($fought, self::decode($this->get('/api/battles/'.$foughtId, $player->headers)));
        self::assertEquals($fought, self::decode($this->post($path, [], $headers)));
        $next = self::decode($this->post($path, [], $player->headers + ['Idempotency-Key' => 'duel-remplace']));
        self::assertSame([], self::equipmentOf($next, 'player'));
        self::assertSame(['HEAD' => ['key' => 'CROWN_OF_THE_TIRELESS', 'artworkVersion' => null]], self::equipmentOf($next, 'enemy'));
    }

    public function testRetiredWaistArtworkDoesNotRemoveTheEquippedInventoryItem(): void
    {
        $player = $this->openAccount();
        $this->grant($player, 'ALL_PURPOSE_FLASK');
        $this->equip($player, 'ALL_PURPOSE_FLASK', 'ACCESSORY');
        self::assertSame(['ACCESSORY' => ['key' => 'ALL_PURPOSE_FLASK', 'artworkVersion' => null]], self::equipmentOf($this->fight($player), 'player'));
        $inventory = self::decode($this->get('/api/inventory', $player->headers));
        self::assertIsArray($inventory['equipment']);
        self::assertIsArray($inventory['equipment']['ACCESSORY']);
        self::assertSame('ALL_PURPOSE_FLASK', $inventory['equipment']['ACCESSORY']['key']);
    }

    /**
     * @param array<mixed> $battle
     *
     * @return array<mixed>
     */
    private static function equipmentOf(array $battle, string $side): array
    {
        $fighter = $battle[$side];
        self::assertIsArray($fighter);
        $equipment = $fighter['equipment'];
        self::assertIsArray($equipment);

        return $equipment;
    }

    private function grant(Account $account, string $key): void
    {
        $items = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $items);
        $items->grant($account->id, $key, Uuid::v7(), new DateTimeImmutable());
    }

    private function equip(Account $account, string $key, string $slot = 'HEAD'): void
    {
        $response = $this->send('PUT', '/api/inventory/equipment/'.$slot, ['itemKey' => $key], $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    /** @return array<mixed> */
    private function fight(Account $account): array
    {
        $response = $this->post('/api/battles', [], $account->headers + ['Idempotency-Key' => Uuid::v7()->toRfc4122()]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        return self::decode($response);
    }
}
