<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/players/{id}` — la seule route de l'API qui sert les données de quelqu'un
 * d'autre, et celle qui a fait réécrire un invariant.
 *
 * Le test qui porte la décision est
 * {@see self::testAStrangerAndAnUnknownUuidAreIndistinguishable()} : c'est lui qui empêche
 * la route de devenir un test d'existence sur des UUID v7, lesquels encodent leur instant
 * de création et se devinent donc par plage temporelle.
 */
final class PlayerProfileTest extends ApiTestCase
{
    public function testAPlayerSeesHisOwnPublicProfile(): void
    {
        $player = $this->openAccount();

        $response = $this->get('/api/players/'.$player->id->toRfc4122(), $player->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame($player->id->toRfc4122(), $body['id']);
        self::assertSame('Bob', $body['displayName']);
        self::assertSame(1, $body['level']);
        self::assertIsString($body['registeredAt']);
    }

    /** Se voir soi-même ne dépend pas d'avoir une guilde : c'est la première clause du voter. */
    public function testSeeingOneselfWorksWithoutAnyGuild(): void
    {
        $loner = $this->openAccount();

        self::assertSame(
            Response::HTTP_OK,
            $this->get('/api/players/'.$loner->id->toRfc4122(), $loner->headers)->getStatusCode(),
        );
    }

    public function testAnEmptyProfileHasNoGuildOrWeekReference(): void
    {
        $player = $this->openAccount();
        $body = self::decode($this->get('/api/players/'.$player->id->toRfc4122(), $player->headers));
        $home = self::decode($this->get('/api/progression/week', $player->headers));

        self::assertNull($body['guild']);
        self::assertIsArray($body['week']);
        self::assertSame(['startsAt', 'resetsAt', 'current', 'previous'], array_keys($body['week']));
        self::assertSame(array_intersect_key($home, $body['week']), $body['week']);
        self::assertSame(['xp' => 0, 'sessions' => 0, 'attributes' => ['strength' => 0, 'endurance' => 0, 'mobility' => 0, 'dexterity' => 0]], $body['week']['current']);
        self::assertNull($body['week']['previous']);
    }

    public function testATeammatesRadarMatchesHisHomeWithoutPrivateEntries(): void
    {
        [$founder, $member] = $this->guildOfTwo();
        self::assertSame(Response::HTTP_OK, $this->send('PATCH', '/api/me', ['timezone' => 'Pacific/Honolulu'], $member->headers)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->send('PATCH', '/api/me', ['timezone' => 'Asia/Tokyo'], $founder->headers)->getStatusCode());
        $rules = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rules);
        $window = AlamCalendar::runtime($rules)->week(new DateTimeImmutable());
        $grant = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grant);
        // Les bornes sont celles du sport, même si tous ces crédits sont importés maintenant.
        $previousSession = Uuid::v7();
        ($grant)(new GrantXp($member->id, $previousSession, Discipline::Running, 1800, $window['start']->modify('-1 second')));
        ($grant)(new GrantXp($member->id, Uuid::v7(), Discipline::Cycling, 1200, $window['start']));
        $cancelled = Uuid::v7();
        ($grant)(new GrantXp($member->id, $cancelled, Discipline::Running, 600, $window['start']->modify('+1 minute')));
        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $credit = $ledger->recordedFor($cancelled, XpReason::SessionCompleted);
        self::assertInstanceOf(XpTransaction::class, $credit);
        $ledger->add(XpTransaction::reversalOf($credit));
        $ledger->commit();
        // Le lecteur n'alimente jamais le radar de la cible.
        ($grant)(new GrantXp($founder->id, Uuid::v7(), Discipline::Running, 3600, $window['start']));

        $home = self::decode($this->get('/api/progression/week', $member->headers));
        $body = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));
        self::assertIsArray($body['week']);
        self::assertSame(['startsAt', 'resetsAt', 'current', 'previous'], array_keys($body['week']));
        self::assertSame(array_intersect_key($home, $body['week']), $body['week']);
        self::assertSame($window['start']->format(DateTimeInterface::ATOM), $body['week']['startsAt']);
        self::assertSame($window['reset']->format(DateTimeInterface::ATOM), $body['week']['resetsAt']);
        self::assertIsArray($body['week']['current']);
        self::assertIsArray($body['week']['previous']);
        self::assertSame(1, $body['week']['current']['sessions']);
        self::assertSame(1, $body['week']['previous']['sessions']);
        self::assertIsArray($home['entries']);
        self::assertCount(3, $home['entries']);
        self::assertSame(['xp', 'sessions', 'attributes'], array_keys($body['week']['current']));
        self::assertSame(['xp', 'sessions', 'attributes'], array_keys($body['week']['previous']));
        self::assertArrayNotHasKey('entries', $body);
        self::assertArrayNotHasKey('trainingPlans', $body);

        $previousCredit = $ledger->recordedFor($previousSession, XpReason::SessionCompleted);
        self::assertInstanceOf(XpTransaction::class, $previousCredit);
        $ledger->add(XpTransaction::reversalOf($previousCredit));
        $ledger->commit();
        $withoutReference = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));
        self::assertIsArray($withoutReference['week']);
        self::assertNull($withoutReference['week']['previous'], 'Une référence entièrement annulée ne donne aucune cible.');
        self::assertSame($body['week']['current'], $withoutReference['week']['current']);
    }

    public function testEveryVisibleGuildLinkOpensTheReadersExistingGuild(): void
    {
        [$founder, $member] = $this->guildOfTwo();
        foreach ([$founder, $member] as $viewer) {
            $mine = self::decode($this->get('/api/guilds/mine', $viewer->headers))['guild'];
            self::assertIsArray($mine);
            self::assertIsString($mine['id']);
            self::assertSame(['symbol' => 'DUMBBELL', 'backgroundColor' => 'NIGHT', 'symbolColor' => 'CYAN'], $mine['emblem']);
            foreach ([$founder, $member] as $target) {
                $body = self::decode($this->get('/api/players/'.$target->id->toRfc4122(), $viewer->headers));
                self::assertSame(['id' => $mine['id'], 'name' => $mine['name'], 'emblem' => $mine['emblem']], $body['guild']);
                self::assertSame(Response::HTTP_OK, $this->get('/api/guilds/'.$mine['id'], $viewer->headers)->getStatusCode());
            }
        }
    }

    public function testTheSavedGuildEmblemIsVisibleOnProfilesWithoutChangingProgression(): void
    {
        [$founder, $member] = $this->guildOfTwo();
        $before = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));
        self::assertIsArray($before['guild']);
        self::assertIsString($before['guild']['id']);
        $emblem = ['symbol' => 'YOGA', 'backgroundColor' => 'MINT', 'symbolColor' => 'BLOSSOM'];
        $saved = $this->send('PATCH', '/api/guilds/'.$before['guild']['id'], ['emblem' => $emblem], $founder->headers);
        self::assertSame(Response::HTTP_OK, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertSame($emblem, self::decode($saved)['emblem']);

        foreach ([$founder, $member] as $viewer) {
            $profile = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $viewer->headers));
            self::assertSame([...$before['guild'], 'emblem' => $emblem], $profile['guild']);
            unset($profile['guild']);
            $unchanged = $before;
            unset($unchanged['guild']);
            self::assertSame($unchanged, $profile);
        }
    }

    public function testATeammateIsVisible(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $seenByFounder = $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers);
        self::assertSame(Response::HTTP_OK, $seenByFounder->getStatusCode(), (string) $seenByFounder->getContent());
        self::assertSame('Carla', self::decode($seenByFounder)['displayName']);

        // Et dans l'autre sens : le voter ne privilégie pas le fondateur.
        $seenByMember = $this->get('/api/players/'.$founder->id->toRfc4122(), $member->headers);
        self::assertSame(Response::HTTP_OK, $seenByMember->getStatusCode());
        self::assertSame('Bob', self::decode($seenByMember)['displayName']);
    }

    /**
     * La répartition d'une pratique est une donnée sociale (#176) : ce que le ledger d'un
     * co-équipier a accumulé doit se lire sur son profil public, pas seulement sur le sien.
     */
    public function testATeammatesCharacteristicsAreVisible(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);

        $granted = ($grantXp)(new GrantXp($member->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));
        $attributes = $granted->snapshot->attributes();

        $body = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        self::assertSame(
            [
                'strength' => $attributes->strength,
                'endurance' => $attributes->endurance,
                'mobility' => $attributes->mobility,
                'dexterity' => $attributes->dexterity,
                'vitality' => $granted->snapshot->vitality(),
            ],
            $body['attributes'],
        );
    }

    public function testATeammatesEquipmentAndStatisticsMatchHisInventoryWithoutPrivateValues(): void
    {
        [$founder, $member] = $this->guildOfTwo();
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);
        $repository->grant($member->id, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $repository->grant($member->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $own = self::decode($this->send('PUT', '/api/inventory/equipment/WEAPON', ['itemKey' => 'IRON_GAUNTLETS'], $member->headers));
        $public = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        self::assertSame($own['statistics'], $public['statistics']);
        self::assertIsArray($public['inventory']);
        self::assertSame(['equipment', 'items'], array_keys($public['inventory']));
        self::assertIsArray($public['inventory']['equipment']);
        self::assertIsArray($own['equipment']);
        self::assertIsArray($own['equipment']['WEAPON']);
        self::assertIsArray($public['inventory']['equipment']['WEAPON']);
        self::assertSame($own['equipment']['WEAPON']['key'], $public['inventory']['equipment']['WEAPON']['key']);
        self::assertIsArray($public['inventory']['items']);
        self::assertCount(2, $public['inventory']['items']);
        foreach ([...$public['inventory']['items'], ...array_filter($public['inventory']['equipment'])] as $item) {
            self::assertIsArray($item);
            self::assertSame(['key', 'kind', 'name', 'rarity', 'slot', 'weaponType', 'modifiers', 'imageUrl', 'quantity'], array_keys($item));
            self::assertNotEmpty($item['modifiers']);
        }
        self::assertIsArray($public['attributes']);
        self::assertIsArray($public['statistics']);
        self::assertIsArray($public['statistics']['attributes']);
        self::assertSame(0, $public['attributes']['strength'], 'Les cercles conservent la progression sans équipement.');
        self::assertSame(['base' => 0, 'equipmentBonus' => 0, 'effective' => 0], $public['statistics']['attributes']['strength']);
    }

    public function testAStrangerIsNotVisible(): void
    {
        $stranger = $this->openAccount('dan@grrind.app', 'Dan');
        self::assertSame(Response::HTTP_CREATED, $this->post('/api/guilds', ['name' => 'Autre guilde'], $stranger->headers)->getStatusCode());
        [$founder] = $this->guildOfTwo();

        $response = $this->get('/api/players/'.$founder->id->toRfc4122(), $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-not-found', self::decode($response)['type']);
        $guild = self::decode($this->get('/api/guilds/mine', $founder->headers))['guild'];
        self::assertIsArray($guild);
        self::assertIsString($guild['id']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/api/guilds/'.$guild['id'], $stranger->headers)->getStatusCode());
    }

    /**
     * **Le test qui porte la décision du ticket.** Un compte réel qu'on n'a pas le droit de
     * voir et un UUID qui ne désigne personne doivent rendre la même réponse au champ près.
     * Les vérifier séparément laisserait les deux chemins diverger, et la route
     * redeviendrait un oracle.
     */
    public function testAStrangerAndAnUnknownUuidAreIndistinguishable(): void
    {
        $stranger = $this->openAccount('dan@grrind.app', 'Dan');
        [$founder] = $this->guildOfTwo();

        $forbidden = $this->get('/api/players/'.$founder->id->toRfc4122(), $stranger->headers);
        $unknown = $this->get('/api/players/'.Uuid::v7()->toRfc4122(), $stranger->headers);

        self::assertSame($forbidden->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::decode($forbidden), self::decode($unknown));
        self::assertNotSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode(), 'Un 403 confirmerait qu\'un compte porte cet UUID.');
    }

    public function testAMalformedIdentifierGetsTheSameAnswer(): void
    {
        $player = $this->openAccount();

        $malformed = $this->get('/api/players/pas-un-uuid', $player->headers);
        $unknown = $this->get('/api/players/'.Uuid::v7()->toRfc4122(), $player->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $malformed->getStatusCode());
        self::assertSame(self::decode($unknown), self::decode($malformed));
    }

    /** Quitter la guilde referme la porte : l'autorisation se relit, elle ne se mémorise pas. */
    public function testALeftGuildEndsTheVisibility(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        self::assertSame(Response::HTTP_OK, $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers)->getStatusCode());

        $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers)->getStatusCode(),
        );
    }

    /**
     * **La revue du DTO fait partie du ticket.** Ce sont des données de compte, pas de
     * profil public, et elles ne doivent jamais franchir cette route.
     */
    public function testTheProfileNeverLeaksAccountData(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $body = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        self::assertArrayNotHasKey('email', $body);
        self::assertArrayNotHasKey('timezone', $body);
        self::assertArrayNotHasKey('roles', $body);
        self::assertArrayNotHasKey('password', $body);
        self::assertArrayNotHasKey('nextTitle', $body, 'Le prochain titre visé n\'a de sens que sur son propre profil.');
    }

    /** Le détail enrichit le bloc commun sans charger les sacs dans la liste. */
    public function testTheProfileKeepsTheSameMemberBlock(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $alone = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        $guild = self::decode($this->get('/api/guilds/mine', $founder->headers))['guild'];
        self::assertIsArray($guild);
        $members = $guild['members'];
        self::assertIsArray($members);

        $inList = null;
        foreach ($members as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['id'] === $member->id->toRfc4122()) {
                $inList = $candidate;
            }
        }

        self::assertIsArray($inList);

        // La liste ajoute le rôle et la date d'entrée ; tout le reste doit coïncider, sans
        // quoi le client aurait deux types à décoder pour le même objet.
        unset($inList['role'], $inList['joinedAt']);
        self::assertArrayNotHasKey('inventory', $inList);
        self::assertArrayNotHasKey('statistics', $inList);
        self::assertSame($alone['league'], $inList['league']);
        unset($inList['headEquipment'], $inList['accessoryEquipment']);
        unset($alone['inventory'], $alone['statistics'], $alone['week'], $alone['guild']);
        self::assertSame($alone, $inList);
    }

    public function testRefusesAnAnonymousCaller(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/players/'.Uuid::v7()->toRfc4122())->getStatusCode());
    }

    /**
     * @return array{Account, Account}
     */
    private function guildOfTwo(): array
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('carla@grrind.app', 'Carla');

        $created = $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $guildId = self::decode($created)['id'];
        self::assertIsString($guildId);

        $issued = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $issued->getStatusCode());
        $code = self::decode($issued)['code'];
        self::assertIsString($code);

        self::assertSame(
            Response::HTTP_OK,
            $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode(),
        );

        return [$founder, $member];
    }
}
