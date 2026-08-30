<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleOutcome;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\Dodge;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\ExtraTurn;
use App\Combat\Domain\Fighter;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Sans base ni conteneur : ce que {@see Battle::conclude()} produit ne dépend que de ses
 * entrées. Ce qui se démontre ici est la forme **stable** de `playerSnapshot()`,
 * `enemySnapshot()` et `timeline()` — voir le docblock de la classe pour pourquoi ni un cast
 * d'objet ni un `json_encode` implicite ne conviendraient.
 */
final class BattleTest extends TestCase
{
    public function testThePlayerSnapshotCarriesTheFourAttributesTheVitalityAndTheDerivedFighter(): void
    {
        $battle = self::battleOf();

        self::assertSame(
            [
                'attributes' => ['strength' => 10, 'endurance' => 20, 'mobility' => 30, 'dexterity' => 40],
                'vitality' => 500,
                'fighter' => ['hp' => 150, 'damage' => 12, 'mitigationPermille' => 100, 'extraTurnPermille' => 50, 'dodgePermille' => 25],
            ],
            $battle->playerSnapshot(),
        );
    }

    public function testTheEnemySnapshotCarriesTheCatalogueKeyAndTheFighterItWas(): void
    {
        $battle = self::battleOf();

        self::assertSame(
            [
                'key' => 'SAND_JACKAL',
                'fighter' => ['hp' => 120, 'damage' => 10, 'mitigationPermille' => 50, 'extraTurnPermille' => 40, 'dodgePermille' => 20],
            ],
            $battle->enemySnapshot(),
        );
    }

    /**
     * La forme exacte, gelée : un `type` par ligne, jamais l'ordre de déclaration d'une
     * classe PHP. C'est cette forme que le #212 rendra au client.
     */
    public function testTheTimelineIsSerializedToAnExplicitAndStableShape(): void
    {
        $battle = self::battleOf(outcome: new BattleOutcome(
            BattleResult::Victory,
            [
                new BattleStarted(150, 120),
                new Attack(Actor::Player, 12, 3, 108),
                new ExtraTurn(Actor::Player),
                new Dodge(Actor::Player),
                new Attack(Actor::Player, 12, 3, 96),
                new BattleFinished(BattleResult::Victory),
            ],
            2,
        ));

        self::assertSame(
            [
                ['type' => 'BATTLE_STARTED', 'playerHp' => 150, 'enemyHp' => 120],
                ['type' => 'ATTACK', 'attacker' => 'PLAYER', 'damage' => 12, 'mitigated' => 3, 'targetHpRemaining' => 108],
                ['type' => 'EXTRA_TURN', 'actor' => 'PLAYER'],
                ['type' => 'DODGE', 'attacker' => 'PLAYER'],
                ['type' => 'ATTACK', 'attacker' => 'PLAYER', 'damage' => 12, 'mitigated' => 3, 'targetHpRemaining' => 96],
                ['type' => 'BATTLE_FINISHED', 'result' => 'VICTORY'],
            ],
            $battle->timeline(),
        );
    }

    /**
     * Le résultat et le nombre de tours sont portés en clair, sans avoir à parcourir la
     * timeline pour les retrouver.
     */
    public function testResultAndTurnsAreCarriedAsPlainFields(): void
    {
        $battle = self::battleOf(outcome: new BattleOutcome(BattleResult::Defeat, [new BattleStarted(1, 1)], 7));

        self::assertSame(BattleResult::Defeat, $battle->result());
        self::assertSame(7, $battle->turns());
    }

    /**
     * L'aller-retour hexadécimal : ce que `Battle` stocke doit reproduire exactement les
     * 32 octets tirés par `random_bytes(32)`, jamais une graine tronquée ou réencodée.
     */
    public function testTheSeedRoundTripsThroughItsHexadecimalRepresentation(): void
    {
        $raw = random_bytes(32);

        $battle = self::battleOf(seed: $raw);

        self::assertSame(64, \strlen($battle->seed()));
        self::assertSame($raw, hex2bin($battle->seed()));
    }

    /**
     * `Xoshiro256StarStar` exige exactement 32 octets — voir le docblock de la classe pour
     * le piège que ça a coûté au #209. Une graine d'une autre taille ne doit jamais
     * atteindre la base.
     */
    public function testASeedOfTheWrongLengthIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::battleOf(seed: 'trop-courte');
    }

    public function testTheRulesetVersionAndTheFightTimeAreCarriedAsGiven(): void
    {
        $foughtAt = new DateTimeImmutable('2026-08-29T09:00:00+00:00');

        $battle = self::battleOf(rulesetVersion: 'v1-3f2a9c1d4b7e', foughtAt: $foughtAt);

        self::assertSame('v1-3f2a9c1d4b7e', $battle->rulesetVersion());
        self::assertEquals($foughtAt, $battle->foughtAt());
    }

    /**
     * `$id` est fourni par l'appelant depuis le #227 — voir le docblock de la classe pour
     * pourquoi — et non généré ici : la ligne porte exactement celui qu'on lui a donné.
     */
    public function testTheIdIsTheOneGivenByTheCaller(): void
    {
        $id = Uuid::v7();

        $battle = self::battleOf(id: $id);

        self::assertSame($id->toRfc4122(), $battle->id()->toRfc4122());
    }

    /**
     * Le reward est porté tel quel, voir « `$reward` est persisté sur la ligne » dans le
     * docblock de la classe : aucune transformation, aucun recalcul.
     */
    public function testTheRewardIsCarriedAsGiven(): void
    {
        $reward = [
            'loot' => [['key' => 'WORN_RUNNING_SHOES']],
            'coins' => ['gained' => 8, 'before' => 40, 'after' => 48],
        ];

        $battle = self::battleOf(reward: $reward);

        self::assertSame($reward, $battle->reward());
    }

    /**
     * La forme vide, voir `App\Shared\Application\BattleDrop::none()` : `loot` vide, `coins`
     * à gain nul mais un solde réel — jamais des clés absentes.
     */
    public function testAnEmptyRewardIsStillTheFullShape(): void
    {
        $battle = self::battleOf();

        self::assertSame(
            ['loot' => [], 'coins' => ['gained' => 0, 'before' => 0, 'after' => 0]],
            $battle->reward(),
        );
    }

    /**
     * @param ?array{loot: list<array<string, mixed>>, coins: array{gained: int, before: int, after: int}} $reward
     */
    private static function battleOf(
        ?Uuid $id = null,
        ?BattleOutcome $outcome = null,
        ?array $reward = null,
        string $seed = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x20\x21\x22\x23\x24\x25\x26\x27\x28\x29\x30\x31\x32",
        string $rulesetVersion = 'v1-000000000000',
        ?DateTimeImmutable $foughtAt = null,
    ): Battle {
        return Battle::conclude(
            $id ?? Uuid::v7(),
            Uuid::v7(),
            new AttributeGains(10, 20, 30, 40),
            500,
            new Fighter(150, 12, 100, 50, 25),
            new Enemy('SAND_JACKAL', 1, 120, 10, 50, 40, 20),
            new Fighter(120, 10, 50, 40, 20),
            $outcome ?? new BattleOutcome(BattleResult::Victory, [new BattleStarted(150, 120), new BattleFinished(BattleResult::Victory)], 1),
            $reward ?? ['loot' => [], 'coins' => ['gained' => 0, 'before' => 0, 'after' => 0]],
            $seed,
            $rulesetVersion,
            $foughtAt ?? new DateTimeImmutable('2026-08-29T09:00:00+00:00'),
        );
    }
}
