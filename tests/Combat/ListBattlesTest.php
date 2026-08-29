<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Domain\BattleResult;
use App\Shared\UI\Http\Cursor;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Battles;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/battles` — l'historique de ses combats, voir le docblock de
 * `ListBattlesController` pour pourquoi il ne rend qu'un résumé par combat.
 *
 * Les combats sont écrits en base plutôt que joués par HTTP — voir {@see Battles} — pour la
 * même raison que {@see \App\Tests\Training\ListWorkoutsTest} écrit ses workouts directement :
 * ce qui se vérifie ici est une **lecture**, et un tirage aléatoire n'a pas de position à
 * dicter.
 */
final class ListBattlesTest extends ApiTestCase
{
    use Battles;

    public function testAnEmptyHistoryIsAnEmptyListAndNotAnError(): void
    {
        $bob = $this->openAccount();

        $body = $this->history($bob);

        self::assertSame([], $body['battles']);
        self::assertNull($body['nextCursor']);
    }

    public function testTheMostRecentlyFoughtComeFirst(): void
    {
        $bob = $this->openAccount();

        // Écrits dans le désordre, exprès : c'est `foughtAt` qui décide, pas l'ordre d'écriture.
        $mercredi = $this->recordBattle($bob, new DateTimeImmutable('2026-07-15T08:00:00+00:00'));
        $lundi = $this->recordBattle($bob, new DateTimeImmutable('2026-07-13T08:00:00+00:00'));
        $mardi = $this->recordBattle($bob, new DateTimeImmutable('2026-07-14T08:00:00+00:00'));

        self::assertSame([$mercredi, $mardi, $lundi], $this->idsOf($this->history($bob)));
    }

    /**
     * La décision du #209 : `result` n'a que deux valeurs, y compris pour un combat que
     * `max_turns` a interrompu sans KO — le meilleur ratio de PV tranche, un match nul n'a pas
     * de mise en scène. Fabriqué exprès, `turns` au plafond de `combat.yaml`.
     */
    public function testEveryEntryCarriesAResultEvenOneConcludedByMaxTurns(): void
    {
        $bob = $this->openAccount();

        $this->recordBattle($bob, new DateTimeImmutable('2026-07-15T08:00:00+00:00'), BattleResult::Victory);
        $this->recordBattle($bob, new DateTimeImmutable('2026-07-14T08:00:00+00:00'), BattleResult::Defeat, turns: 200);

        $battles = $this->history($bob)['battles'];
        self::assertCount(2, $battles);

        foreach ($battles as $battle) {
            self::assertIsArray($battle);
            self::assertContains($battle['result'], ['VICTORY', 'DEFEAT']);
        }

        self::assertSame('VICTORY', $battles[0]['result']);
        self::assertSame('DEFEAT', $battles[1]['result']);
        self::assertSame(200, $battles[1]['turns']);
    }

    /**
     * La pagination par curseur : elle désigne une position dans les données, pas un rang. Un
     * combat livré entre deux pages ne décale donc rien.
     */
    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();

        $all = [];
        for ($day = 5; $day >= 1; --$day) {
            $all[] = $this->recordBattle($bob, new DateTimeImmutable(\sprintf('2026-07-0%dT08:00:00+00:00', $day)));
        }

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($all, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);

        // Un combat livré pendant le défilement ne décale rien : il se range à sa date et n'a
        // aucun effet sur les pages déjà servies.
        $intercale = $this->recordBattle($bob, new DateTimeImmutable('2026-06-01T08:00:00+00:00'));

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($all, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame([$all[4], $intercale], $this->idsOf($lastPage));

        // Plus rien après : le client s'arrête là, sans avoir jamais eu besoin d'un total.
        self::assertNull($lastPage['nextCursor']);
    }

    /**
     * **Le cas que le curseur composite existe pour couvrir.** Deux combats livrés à la même
     * seconde ne doivent être ni rendus deux fois ni sautés — un curseur qui ne porterait que
     * la date s'arrêterait entre les deux sans savoir lequel il a déjà servi.
     */
    public function testTwoBattlesFoughtAtTheSameSecondAreNeitherRepeatedNorSkipped(): void
    {
        $bob = $this->openAccount();
        $sameSecond = new DateTimeImmutable('2026-07-15T08:30:00+00:00');

        $this->recordBattle($bob, $sameSecond);
        $this->recordBattle($bob, $sameSecond);
        $this->recordBattle($bob, $sameSecond);

        $seen = [];
        $cursor = null;

        do {
            $page = $this->history($bob, null === $cursor ? ['limit' => 1] : ['limit' => 1, 'cursor' => $cursor]);
            $seen = [...$seen, ...$this->idsOf($page)];
            $cursor = $page['nextCursor'];
        } while (null !== $cursor);

        self::assertCount(3, $seen);
        self::assertSame($seen, array_unique($seen), 'Aucun combat ne doit être rendu deux fois.');
    }

    /**
     * L'isolation ne tient pas à un contrôle qu'on pourrait oublier : le compte n'est pas un
     * paramètre de la requête, il vient du jeton, et le filtre `playerId` s'applique **avant**
     * la position que désigne le curseur. Même un curseur forgé exactement à la position d'un
     * combat d'un autre compte ne fait apparaître ni ce combat, ni aucun autre combat étranger.
     */
    public function testAnAccountNeverSeesAnothersBattlesEvenByForcingTheCursor(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $aliceFoughtAt = new DateTimeImmutable('2026-07-16T08:00:00+00:00');
        $alicesBattle = $this->recordBattle($alice, $aliceFoughtAt);
        $earlier = $this->recordBattle($bob, new DateTimeImmutable('2026-07-15T08:00:00+00:00'));
        $later = $this->recordBattle($bob, new DateTimeImmutable('2026-07-17T08:00:00+00:00'));

        self::assertSame([$later, $earlier], $this->idsOf($this->history($bob)));

        // Le curseur d'Alice, rejoué par Bob : la position se lit sur le combat de Bob dont la
        // date précède celle d'Alice — jamais sur le combat d'Alice lui-même, qui n'apparaît à
        // aucun moment dans la page de Bob.
        $forgedCursor = Cursor::of($aliceFoughtAt, Uuid::fromString($alicesBattle))->encoded();
        self::assertSame([$earlier], $this->idsOf($this->history($bob, ['cursor' => $forgedCursor])));
    }

    public function testRefusesALimitBeyondTheCeiling(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/battles?limit=500', $bob->headers)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/battles?limit=0', $bob->headers)->getStatusCode());
    }

    /**
     * Un curseur bricolé à la main est un 422 qui le nomme, et surtout **pas** une page vide :
     * celle-ci ferait croire au client qu'il est arrivé au bout de l'historique.
     */
    public function testRefusesAnUnreadableCursor(): void
    {
        $bob = $this->openAccount();

        $response = $this->get('/api/battles?cursor=pas-un-curseur', $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    public function testRefusesAnonymousCalls(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/battles')->getStatusCode());
    }

    /** L'ordre des champs d'une entrée est du contrat versionné — voir `BattleSummaryResourcePayloadTest`. */
    public function testTheKeyOrderOfAnEntryIsFixed(): void
    {
        $bob = $this->openAccount();
        $this->recordBattle($bob, new DateTimeImmutable('2026-07-15T08:00:00+00:00'));

        $battle = $this->history($bob)['battles'][0];
        self::assertIsArray($battle);

        self::assertSame(['id', 'result', 'enemy', 'turns', 'foughtAt'], array_keys($battle));

        $enemy = $battle['enemy'];
        self::assertIsArray($enemy);
        self::assertSame(['key', 'name'], array_keys($enemy));
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array{battles: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function history(Account $account, array $parameters = []): array
    {
        $response = $this->get('/api/battles?'.http_build_query($parameters), $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['battles']);
        self::assertTrue(null === $body['nextCursor'] || \is_string($body['nextCursor']));

        /** @var array{battles: list<array<string, mixed>>, nextCursor: string|null} $body */
        return $body;
    }

    /**
     * @param array{battles: list<array<string, mixed>>, nextCursor: string|null} $page
     *
     * @return list<string>
     */
    private function idsOf(array $page): array
    {
        return array_map(static function (array $battle): string {
            self::assertIsString($battle['id']);

            return $battle['id'];
        }, $page['battles']);
    }
}
