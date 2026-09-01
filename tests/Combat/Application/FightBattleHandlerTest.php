<?php

declare(strict_types=1);

namespace App\Tests\Combat\Application;

use App\Combat\Application\FightBattle;
use App\Combat\Application\FightBattleHandler;
use App\Combat\Application\FighterFactory;
use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Progression\Domain\LevelCurve;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le pipeline entier contre les vrais services du conteneur — `combat.yaml` chargé, la
 * vraie base. Ce qui compte ici ne se démontre pas en mémoire : que la ligne écrite
 * reproduit fidèlement ce que le moteur a produit, et que le `ruleset_version` qu'elle
 * porte est bien celui que le conteneur a chargé, pas une valeur écrite en dur dans le
 * handler.
 *
 * Pas de compte : le joueur n'est qu'un UUID nu, sans ligne de progression — c'est le cas
 * normal d'un compte tout juste inscrit, voir {@see PlayerProgression::untouched()}.
 */
final class FightBattleHandlerTest extends KernelTestCase
{
    private FightBattleHandler $handler;
    private BattleRepository $battles;
    private EntityManagerInterface $entityManager;
    private string $rulesetVersion;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $handler = $container->get(FightBattleHandler::class);
        self::assertInstanceOf(FightBattleHandler::class, $handler);
        $this->handler = $handler;

        $battles = $container->get(BattleRepository::class);
        self::assertInstanceOf(BattleRepository::class, $battles);
        $this->battles = $battles;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $rulesets = $container->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rulesets);
        $this->rulesetVersion = $rulesets->version();

        $connection = $this->entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('TRUNCATE combat_battle');
    }

    public function testAFreshPlayerFightsAndTheBattleIsWritten(): void
    {
        $playerId = Uuid::v7();

        $battle = ($this->handler)(new FightBattle($playerId));

        self::assertSame($playerId->toRfc4122(), $battle->playerId()->toRfc4122());
        self::assertContains($battle->result(), [BattleResult::Victory, BattleResult::Defeat]);
        self::assertGreaterThan(0, $battle->turns());

        $this->entityManager->clear();
        $reloaded = $this->battles->find($battle->id());
        self::assertInstanceOf(Battle::class, $reloaded);
        self::assertSame($battle->result(), $reloaded->result());

        // `assertEquals`, pas `assertSame` : PostgreSQL réordonne les clés d'un objet
        // JSONB (par longueur puis ordre alphabétique) au stockage — un objet JSON n'a pas
        // d'ordre de clé signifiant, seul l'ordre des éléments de la liste l'est, et celui-là
        // survit. Voir le docblock de `Battle`.
        self::assertEquals($battle->timeline(), $reloaded->timeline());
    }

    /**
     * Le `ruleset_version` écrit est celui du conteneur, pas une constante recopiée dans
     * le handler : le jour où `combat.yaml` change, cette ligne doit suivre sans qu'on
     * touche à `FightBattleHandler`.
     */
    public function testTheWrittenRulesetVersionIsTheContainerS(): void
    {
        $battle = ($this->handler)(new FightBattle(Uuid::v7()));

        self::assertSame($this->rulesetVersion, $battle->rulesetVersion());
    }

    /**
     * Deux combats joués à graine forcée identique, avec les mêmes collaborateurs que le
     * handler emploie (résolus depuis le conteneur, pas reconstruits à la main), écrivent
     * deux lignes identiques une fois relues en base — timeline, résultat et tours compris.
     * C'est ce qui rend une ligne auditable : la graine et les snapshots prouvent que la
     * timeline sort bien de ces entrées.
     */
    public function testTwoBattlesPlayedWithTheSameForcedSeedWriteIdenticalLines(): void
    {
        $container = self::getContainer();

        $fighters = $container->get(FighterFactory::class);
        self::assertInstanceOf(FighterFactory::class, $fighters);

        $enemies = $container->get(EnemyCatalog::class);
        self::assertInstanceOf(EnemyCatalog::class, $enemies);

        $simulator = $container->get(BattleSimulator::class);
        self::assertInstanceOf(BattleSimulator::class, $simulator);

        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $now = $clock->now();

        // 32 octets exacts, forcés — jamais un hash d'une chaîne, voir le docblock de
        // `Battle` pour le piège que ça a coûté au #209.
        $seed = random_bytes(32);

        $progression = PlayerProgression::untouched();
        $enemy = $enemies->forLevel($progression->level);
        $enemyFighter = $fighters->forEnemy($enemy);
        // Le joueur de ce combat n'a pas de compte réel : aucun contributeur ne branche
        // encore de modificateur (#224), donc l'UUID passé au resolver n'a pas d'incidence.
        $player = $fighters->forPlayer($progression, Uuid::v7(), $now);

        $write = function () use ($simulator, $seed, $progression, $player, $enemy, $enemyFighter, $now): Battle {
            $outcome = $simulator->fight($player, $enemyFighter, new Randomizer(new Xoshiro256StarStar($seed)));

            $battle = Battle::conclude(
                Uuid::v7(),
                Uuid::v7(),
                $progression->attributes,
                $progression->vitality,
                $player,
                $enemy,
                $enemyFighter,
                $outcome,
                ['loot' => [], 'coins' => ['gained' => 0, 'before' => 0, 'after' => 0]],
                $seed,
                $this->rulesetVersion,
                $now,
            );

            $this->battles->add($battle);
            $this->battles->commit();

            return $battle;
        };

        $first = $write();
        $second = $write();

        $this->entityManager->clear();

        $firstReloaded = $this->battles->find($first->id());
        $secondReloaded = $this->battles->find($second->id());
        self::assertInstanceOf(Battle::class, $firstReloaded);
        self::assertInstanceOf(Battle::class, $secondReloaded);

        self::assertSame($firstReloaded->result(), $secondReloaded->result());
        self::assertSame($firstReloaded->turns(), $secondReloaded->turns());
        self::assertSame($firstReloaded->timeline(), $secondReloaded->timeline());
        self::assertSame($firstReloaded->playerSnapshot(), $secondReloaded->playerSnapshot());
        self::assertSame($firstReloaded->enemySnapshot(), $secondReloaded->enemySnapshot());
        self::assertSame($firstReloaded->seed(), $secondReloaded->seed());
    }

    /**
     * Un boss nommé s'affronte dès que le `minimum_level` de `combat.yaml` est atteint, et
     * le combat s'écrit exactement comme n'importe quel autre (#219) — même pipeline, seule
     * la résolution de l'ennemi change. `DUNE_SOVEREIGN` exige le niveau 10, 3 060 XP à ce
     * seuil (`levels.yaml`) ; c'est le total minimal qui l'atteint, le cas le plus
     * défavorable qu'une requête légitime puisse produire.
     */
    public function testAPlayerCanFightABossOnceTheMinimumLevelIsReached(): void
    {
        $playerId = Uuid::v7();
        $this->levelPlayerTo($playerId, 3_060);

        $battle = ($this->handler)(new FightBattle($playerId, 'DUNE_SOVEREIGN'));

        self::assertSame('DUNE_SOVEREIGN', $battle->enemySnapshot()['key']);

        $this->entityManager->clear();
        $reloaded = $this->battles->find($battle->id());
        self::assertInstanceOf(Battle::class, $reloaded);
        self::assertSame('DUNE_SOVEREIGN', $reloaded->enemySnapshot()['key']);
    }

    /**
     * Verrouille, reprojette et écrit une ligne `progression_snapshot` directement — sans
     * passer par un import ou un `GrantXp` réel, qui demanderaient plusieurs journées de
     * sport pour atteindre un total pareil. Même geste que
     * {@see \App\Tests\Progression\GrantXpTest::seedLedger()}, un cran plus loin : c'est le
     * snapshot que `PlayerProgressions` lit, jamais le ledger — voir le docblock de
     * `TranslatedPlayerProgressions`.
     */
    private function levelPlayerTo(Uuid $playerId, int $totalXp): void
    {
        $container = self::getContainer();

        $snapshots = $container->get(ProgressionSnapshotRepository::class);
        self::assertInstanceOf(ProgressionSnapshotRepository::class, $snapshots);

        $curve = self::levelCurve();
        $vitality = self::vitalityRules();
        $attributes = new AttributeGains(0, 0, 0, 0);
        $now = new DateTimeImmutable();

        $this->entityManager->wrapInTransaction(static function () use ($snapshots, $playerId, $totalXp, $attributes, $curve, $vitality, $now): void {
            $snapshot = $snapshots->lockFor($playerId, $curve, $vitality);
            $snapshot->retotal($totalXp, $attributes, $curve, $vitality, $now);
        });
    }

    /**
     * La courbe livrée, construite depuis son paramètre — même geste que
     * {@see \App\Tests\Progression\GrantXpTest::curve()}.
     */
    private static function levelCurve(): LevelCurve
    {
        $levels = self::getContainer()->getParameter('game.levels.levels');
        self::assertIsArray($levels);

        /** @var list<array{level: int, total_xp: int, skill_points: int}> $levels */
        return new LevelCurve($levels);
    }

    /**
     * Même geste que {@see \App\Tests\Progression\GrantXpTest::vitality()}.
     */
    private static function vitalityRules(): Vitality
    {
        $container = self::getContainer();

        $floorPermille = $container->getParameter('game.attributes.vitality.floor_permille');
        self::assertIsInt($floorPermille);

        $targetActiveKcal = $container->getParameter('game.attributes.vitality.target_active_kcal');
        self::assertIsInt($targetActiveKcal);

        $bonusCapPermille = $container->getParameter('game.attributes.vitality.bonus_cap_permille');
        self::assertIsInt($bonusCapPermille);

        return new Vitality($floorPermille, $targetActiveKcal, $bonusCapPermille);
    }
}
