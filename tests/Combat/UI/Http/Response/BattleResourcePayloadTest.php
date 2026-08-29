<?php

declare(strict_types=1);

namespace App\Tests\Combat\UI\Http\Response;

use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleOutcome;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\ExtraTurn;
use App\Combat\Domain\Fighter;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\BattleResource;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * **L'ordre des champs est l'ordre de l'animation**, et c'est le contrat le plus coûteux à
 * casser du module — même règle que {@see \App\Tests\Training\RewardSummaryPayloadTest}.
 *
 * Sans HTTP et sans base : {@see Battle::conclude()} (#211) est déjà pur, et
 * {@see EnemyCatalog} ne dépend d'aucun conteneur. Seul {@see EnemyTranslator} a besoin d'un
 * `TranslatorInterface` — un stub minimal suffit ici ; la vraie traduction se prouve dans
 * {@see \App\Tests\Combat\EnemyTranslationsTest}.
 */
final class BattleResourcePayloadTest extends TestCase
{
    public function testTheKeyOrderIsTheAnimationOrder(): void
    {
        $payload = self::resource()->toArray();

        self::assertSame(
            ['id', 'result', 'turns', 'foughtAt', 'player', 'enemy', 'events', 'rewards'],
            array_keys($payload),
            'Un champ déplacé change la mise en scène du combat. Si c\'est voulu, le client doit être prévenu avant.',
        );

        $player = $payload['player'];
        self::assertIsArray($player);
        self::assertSame(['hp', 'damage', 'mitigationPercent', 'extraTurnPercent'], array_keys($player));

        $enemy = $payload['enemy'];
        self::assertIsArray($enemy);
        self::assertSame(['key', 'name', 'hp', 'damage', 'mitigationPercent', 'extraTurnPercent'], array_keys($enemy));

        $events = $payload['events'];
        self::assertIsArray($events);
        self::assertNotEmpty($events);
        $started = $events[0];
        self::assertIsArray($started);
        self::assertSame(['type', 'playerHp', 'enemyHp'], array_keys($started));
    }

    /**
     * Le domaine porte des millièmes, le contrat des pourcentages : la conversion est une
     * division entière tronquée par 10, jamais deux taux que le client recomposerait.
     */
    public function testThePermilleFieldsAreConvertedToTruncatedPercentages(): void
    {
        $payload = self::resource()->toArray();

        $player = $payload['player'];
        self::assertIsArray($player);
        // 105 ‰ tronqué, pas arrondi : 10, jamais 11.
        self::assertSame(10, $player['mitigationPercent']);
        self::assertSame(5, $player['extraTurnPercent']);
    }

    /**
     * Aucune récompense en V1 : présente et vide, jamais absente — même argument que `loot`,
     * `streak` et `unlockableNodes` sur le `RewardSummary`.
     */
    public function testRewardsIsPresentAndEmpty(): void
    {
        self::assertSame([], self::resource()->toArray()['rewards']);
    }

    /** Le nom vient du traducteur, jamais de la clé brute stockée dans le snapshot. */
    public function testTheEnemyNameComesFromTheTranslatorNotTheRawKey(): void
    {
        $enemy = self::resource()->toArray()['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('SAND_JACKAL', $enemy['key']);
        self::assertSame('Chacal des sables', $enemy['name']);
    }

    private static function resource(): BattleResource
    {
        $battle = Battle::conclude(
            Uuid::v7(),
            new AttributeGains(10, 20, 30, 40),
            500,
            // 105 ‰ et 55 ‰ : voir testThePermilleFieldsAreConvertedToTruncatedPercentages.
            new Fighter(150, 12, 105, 55),
            new Enemy('SAND_JACKAL', 1, 120, 10, 50, 40),
            new Fighter(120, 10, 50, 40),
            new BattleOutcome(
                BattleResult::Victory,
                [
                    new BattleStarted(150, 120),
                    new Attack(Actor::Player, 12, 3, 108),
                    new ExtraTurn(Actor::Player),
                    new BattleFinished(BattleResult::Victory),
                ],
                2,
            ),
            random_bytes(32),
            'v1-000000000000',
            new DateTimeImmutable('2026-08-29T09:00:00+00:00'),
        );

        $enemies = new EnemyCatalog([
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 10, 'mitigation_permille' => 50, 'extra_turn_permille' => 40],
        ]);

        return BattleResource::from($battle, $enemies, new EnemyTranslator(self::stubTranslator()));
    }

    private static function stubTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'sand_jackal.name' === $id ? 'Chacal des sables' : $id;
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };
    }
}
