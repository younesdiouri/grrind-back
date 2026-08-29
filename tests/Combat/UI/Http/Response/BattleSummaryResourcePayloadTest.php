<?php

declare(strict_types=1);

namespace App\Tests\Combat\UI\Http\Response;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleOutcome;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\BattleSummaryResource;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * **L'ordre des champs est un contrat**, comme pour {@see BattleResourcePayloadTest}
 * et {@see \App\Tests\Combat\EnemiesTest} pour une entrée du catalogue.
 *
 * Sans HTTP et sans base : {@see Battle::conclude()} est pur, et {@see BattleSummaryResource::from()}
 * ne consulte pas `EnemyCatalog` — même correction que sur `BattleResource`, voir son docblock.
 */
final class BattleSummaryResourcePayloadTest extends TestCase
{
    public function testTheKeyOrderIsFixed(): void
    {
        $payload = self::resource()->toArray();

        self::assertSame(['id', 'result', 'enemy', 'turns', 'foughtAt'], array_keys($payload));

        $enemy = $payload['enemy'];
        self::assertIsArray($enemy);
        self::assertSame(['key', 'name'], array_keys($enemy));
    }

    /**
     * La décision du #209 : même quand `max_turns` interrompt le combat sans KO, le meilleur
     * ratio de PV l'emporte — un match nul n'a pas de mise en scène. `result` reste l'un des
     * deux seuls cas de `BattleResult`, ici fabriqué exprès avec `turns` au plafond.
     */
    public function testAMaxTurnsConclusionStillRendersOneOfTheTwoResults(): void
    {
        $battle = self::battleConcludedByMaxTurns(BattleResult::Defeat);

        $payload = BattleSummaryResource::from($battle, new EnemyTranslator(self::stubTranslator()))->toArray();

        self::assertSame('DEFEAT', $payload['result']);
        self::assertContains($payload['result'], ['VICTORY', 'DEFEAT']);
    }

    /** Le nom vient du traducteur, jamais de la clé brute stockée dans le snapshot. */
    public function testTheEnemyNameComesFromTheTranslatorNotTheRawKey(): void
    {
        $enemy = self::resource()->toArray()['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('SAND_JACKAL', $enemy['key']);
        self::assertSame('Chacal des sables', $enemy['name']);
    }

    /**
     * Même dégradé que sur `BattleResource` : un ennemi retiré ou renommé de `combat.yaml`
     * après que le combat a été joué ne doit jamais faire tomber la ligne d'historique.
     */
    public function testAnEnemyMissingFromTheCatalogueDegradesInsteadOfCrashing(): void
    {
        $battle = self::battleAgainst('GHOST_ENEMY');

        $payload = BattleSummaryResource::from($battle, new EnemyTranslator(self::stubTranslator()))->toArray();

        $enemy = $payload['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('GHOST_ENEMY', $enemy['key']);
        self::assertSame('ghost_enemy.name', $enemy['name']);
    }

    private static function resource(): BattleSummaryResource
    {
        return BattleSummaryResource::from(self::battleAgainst('SAND_JACKAL'), new EnemyTranslator(self::stubTranslator()));
    }

    private static function battleAgainst(string $enemyKey): Battle
    {
        return Battle::conclude(
            Uuid::v7(),
            new AttributeGains(10, 20, 30, 40),
            500,
            new Fighter(150, 12, 105, 55, 20),
            new Enemy($enemyKey, 1, 120, 10, 50, 40, 30),
            new Fighter(120, 10, 50, 40, 30),
            new BattleOutcome(
                BattleResult::Victory,
                [new BattleStarted(150, 120), new BattleFinished(BattleResult::Victory)],
                2,
            ),
            random_bytes(32),
            'v1-000000000000',
            new DateTimeImmutable('2026-08-29T09:00:00+00:00'),
        );
    }

    private static function battleConcludedByMaxTurns(BattleResult $result): Battle
    {
        return Battle::conclude(
            Uuid::v7(),
            new AttributeGains(10, 20, 30, 40),
            500,
            new Fighter(150, 12, 105, 55, 20),
            new Enemy('SAND_JACKAL', 1, 120, 10, 50, 40, 30),
            new Fighter(120, 10, 50, 40, 30),
            new BattleOutcome(
                $result,
                [new BattleStarted(150, 120), new BattleFinished($result)],
                // Le plafond de `combat.yaml` — voir CombatRulesTest pour sa lecture depuis la
                // config. Aucun KO ne s'est produit : c'est le ratio de PV qui a tranché.
                200,
            ),
            random_bytes(32),
            'v1-000000000000',
            new DateTimeImmutable('2026-08-29T09:00:00+00:00'),
        );
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
