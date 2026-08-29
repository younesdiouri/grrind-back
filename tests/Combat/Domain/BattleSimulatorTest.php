<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\BattleEvent;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\ExtraTurn;
use App\Combat\Domain\Fighter;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le moteur seul, sans base ni horloge : des `Fighter` et un `Randomizer` entrent, une
 * timeline sort. Ce qui se démontre ici n'est pas « ça marche sur un exemple », c'est que
 * la boucle **termine toujours** — voir le docblock de {@see BattleSimulator} pour les deux
 * garde-fous qui le garantissent — et que la timeline qu'elle produit est le contrat
 * d'animation qu'elle prétend être.
 */
final class BattleSimulatorTest extends TestCase
{
    public function testHigherDamageKillsInFewerAttacks(): void
    {
        $simulator = self::simulatorOf();
        $enemy = self::fighterOf(hp: 100, damage: 0, mitigationPermille: 0, extraTurnPermille: 0);

        $strong = self::fighterOf(hp: 500, damage: 50, mitigationPermille: 0, extraTurnPermille: 0);
        $weak = self::fighterOf(hp: 500, damage: 10, mitigationPermille: 0, extraTurnPermille: 0);

        $attacksToKill = static fn (Fighter $player): int => \count(array_filter(
            $simulator->fight($player, $enemy, self::randomizer())->timeline,
            static fn (BattleEvent $event): bool => $event instanceof Attack && Actor::Player === $event->attacker,
        ));

        self::assertLessThan($attacksToKill($weak), $attacksToKill($strong));
    }

    public function testHigherMitigationReducesDamageTakenAndRespectsTheCap(): void
    {
        $simulator = self::simulatorOf();
        $attacker = self::fighterOf(hp: 1000, damage: 100, mitigationPermille: 0, extraTurnPermille: 0);

        $undefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, extraTurnPermille: 0);
        // 699 : juste sous le plafond de 700 que `CombatRules` impose à la dérivation
        // (#210) — ce ticket-ci ne dérive rien, mais la valeur qu'il force ici est celle
        // que la config autorise réellement.
        $heavilyDefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 699, extraTurnPermille: 0);

        $firstHitOn = static fn (Fighter $defender): int => self::firstAttack(
            $simulator->fight($attacker, $defender, self::randomizer())->timeline,
        )->damage;

        self::assertSame(100, $firstHitOn($undefended));
        self::assertLessThan($firstHitOn($undefended), $firstHitOn($heavilyDefended));
        // 100 - floor(100 * 699 / 1000) = 100 - 69 = 31 : la mitigation s'applique, mais
        // reste sous le dégât brut, jamais jusqu'à l'invulnérabilité.
        self::assertSame(31, $firstHitOn($heavilyDefended));
    }

    public function testAnExtraTurnPermilleOfOneThousandAlwaysProcs(): void
    {
        $simulator = self::simulatorOf(maxTurns: 50);

        // L'ennemi encaisse sans jamais mourir avant que le test ait pu observer plusieurs
        // tours supplémentaires d'affilée ; il ne riposte jamais, donc si le joueur ne
        // procait pas systématiquement, la main lui reviendrait et un `Attack` de l'ennemi
        // apparaîtrait dans la timeline.
        $player = self::fighterOf(hp: 100, damage: 1, mitigationPermille: 0, extraTurnPermille: 1000);
        $enemy = self::fighterOf(hp: 100_000, damage: 0, mitigationPermille: 0, extraTurnPermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        $enemyAttacks = array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Attack && Actor::Enemy === $event->attacker);
        $extraTurns = array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof ExtraTurn);

        self::assertSame([], array_values($enemyAttacks));
        self::assertNotSame([], array_values($extraTurns));

        foreach ($extraTurns as $extraTurn) {
            self::assertSame(Actor::Player, $extraTurn->actor);
        }
    }

    public function testAnExtraTurnPermilleOfZeroNeverProcs(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);
        $enemy = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        self::assertSame([], array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof ExtraTurn)));

        // Sans jamais rejouer, la main alterne strictement : joueur, ennemi, joueur, ...
        $attackers = array_map(
            static fn (Attack $attack): Actor => $attack->attacker,
            array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Attack)),
        );
        self::assertSame(Actor::Player, $attackers[0]);
        self::assertSame(Actor::Enemy, $attackers[1]);
        self::assertSame(Actor::Player, $attackers[2]);
    }

    public function testAttackReportsTheAmountAbsorbedByMitigation(): void
    {
        $simulator = self::simulatorOf();
        $attacker = self::fighterOf(hp: 1000, damage: 100, mitigationPermille: 0, extraTurnPermille: 0);

        $undefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, extraTurnPermille: 0);
        // 699 : juste sous le plafond de 700 que `CombatRules` impose à la dérivation (#210).
        $defended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 699, extraTurnPermille: 0);

        $attackOn = static fn (Fighter $defender): Attack => self::firstAttack(
            $simulator->fight($attacker, $defender, self::randomizer())->timeline,
        );

        $undefendedHit = $attackOn($undefended);
        self::assertSame(0, $undefendedHit->mitigated);
        self::assertSame($attacker->damage, $undefendedHit->damage + $undefendedHit->mitigated);

        $defendedHit = $attackOn($defended);
        // 100 - floor(100 * 699 / 1000) = 100 - 69 = 31 : l'absorbé est la différence
        // exacte entre le dégât brut et le dégât porté, tant que le plancher ne mord pas.
        self::assertSame(69, $defendedHit->mitigated);
        self::assertSame(31, $defendedHit->damage);
        self::assertSame($attacker->damage, $defendedHit->damage + $defendedHit->mitigated);
    }

    /**
     * Le seul cas où `damage + mitigated` ne reconstitue plus le dégât brut : le plancher a
     * remonté `damage` au-delà de ce que la mitigation seule aurait laissé passer, sans que
     * `mitigated` — la réduction *théorique*, avant plancher — en soit changé. Voir le
     * docblock d'`Attack` pour pourquoi c'est écrit là plutôt que découvert côté client.
     */
    public function testMitigatedDoesNotReconstituteRawDamageWhenTheFloorBites(): void
    {
        $simulator = self::simulatorOf(minimumDamage: 3);
        $attacker = self::fighterOf(hp: 1000, damage: 4, mitigationPermille: 0, extraTurnPermille: 0);
        $defender = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 999, extraTurnPermille: 0);

        $hit = self::firstAttack($simulator->fight($attacker, $defender, self::randomizer())->timeline);

        // reduction = floor(4 * 999 / 1000) = 3, donc un dégât porté de 4 - 3 = 1 sans
        // plancher ; le plancher le remonte à 3, mais `mitigated` reste 3.
        self::assertSame(3, $hit->mitigated);
        self::assertSame(3, $hit->damage);
        self::assertGreaterThan($attacker->damage, $hit->damage + $hit->mitigated);
    }

    public function testDamageNeverGoesBelowMinimumDamageEvenAgainstHeavyMitigation(): void
    {
        $simulator = self::simulatorOf(minimumDamage: 3);
        // Un dégât brut faible face à une mitigation lourde : sans plancher, la formule
        // rendrait un dégât nul et la cible ne mourrait jamais.
        $attacker = self::fighterOf(hp: 1000, damage: 4, mitigationPermille: 0, extraTurnPermille: 0);
        $defender = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 999, extraTurnPermille: 0);

        $outcome = $simulator->fight($attacker, $defender, self::randomizer());

        foreach ($outcome->timeline as $event) {
            if ($event instanceof Attack) {
                self::assertGreaterThanOrEqual(3, $event->damage);
            }
        }
    }

    public function testTheBattleAlwaysEndsWithAResult(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 100, damage: 15, mitigationPermille: 50, extraTurnPermille: 200);
        $enemy = self::fighterOf(hp: 120, damage: 12, mitigationPermille: 60, extraTurnPermille: 150);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertInstanceOf(BattleResult::class, $outcome->result);
        $last = $outcome->timeline[\count($outcome->timeline) - 1];
        self::assertInstanceOf(BattleFinished::class, $last);
        self::assertSame($outcome->result, $last->result);
    }

    public function testTheSameSeedProducesIdenticalTimelines(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 200, damage: 15, mitigationPermille: 50, extraTurnPermille: 300);
        $enemy = self::fighterOf(hp: 200, damage: 14, mitigationPermille: 40, extraTurnPermille: 250);

        $first = $simulator->fight($player, $enemy, self::randomizer('même graine'));
        $second = $simulator->fight($player, $enemy, self::randomizer('même graine'));

        self::assertEquals($first, $second);
    }

    public function testTheTimelineIsInternallyConsistent(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 150, damage: 20, mitigationPermille: 30, extraTurnPermille: 200);
        $enemy = self::fighterOf(hp: 140, damage: 18, mitigationPermille: 20, extraTurnPermille: 150);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        $first = $outcome->timeline[0];
        self::assertInstanceOf(BattleStarted::class, $first);
        self::assertSame($player->hp, $first->playerHp);
        self::assertSame($enemy->hp, $first->enemyHp);

        $playerHpTrail = [];
        $enemyHpTrail = [];

        foreach ($outcome->timeline as $event) {
            if (!$event instanceof Attack) {
                continue;
            }

            if (Actor::Player === $event->attacker) {
                $enemyHpTrail[] = $event->targetHpRemaining;
            } else {
                $playerHpTrail[] = $event->targetHpRemaining;
            }
        }

        // Les PV restants d'une même cible ne remontent jamais.
        self::assertSame($enemyHpTrail, self::sortedDescending($enemyHpTrail));
        self::assertSame($playerHpTrail, self::sortedDescending($playerHpTrail));

        // KO normal (pas de `max_turns` ici) : l'un des deux trails finit à zéro, et
        // `battle_finished` désigne l'autre comme vainqueur.
        $last = $outcome->timeline[\count($outcome->timeline) - 1];
        self::assertInstanceOf(BattleFinished::class, $last);

        if (0 === $enemyHpTrail[\count($enemyHpTrail) - 1]) {
            self::assertSame(BattleResult::Victory, $last->result);
        } else {
            self::assertSame(BattleResult::Defeat, $last->result);
        }
    }

    public function testMaxTurnsReachedStillProducesAWinner(): void
    {
        // Deux tanks qui ne peuvent pas s'achever en trois tours : la boucle sort par
        // `max_turns`, pas par un KO, et doit tout de même rendre un vainqueur.
        $simulator = self::simulatorOf(maxTurns: 3);
        $player = self::fighterOf(hp: 10_000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);
        $enemy = self::fighterOf(hp: 10_000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(3, $outcome->turns);
        self::assertInstanceOf(BattleResult::class, $outcome->result);

        // Aucun KO ici : personne n'atteint zéro, la sortie vient bien de `max_turns`.
        foreach ($outcome->timeline as $event) {
            if ($event instanceof Attack) {
                self::assertGreaterThan(0, $event->targetHpRemaining);
            }
        }
    }

    /**
     * À `max_turns`, le joueur joue en premier (voir le docblock de la classe) : sur un
     * nombre pair de tours et des combattants strictement symétriques, les deux camps
     * infligent exactement les mêmes dégâts. Une égalité stricte de ratio doit se
     * départager, et c'est le joueur qui l'emporte — un choix de ce ticket, écrit en toutes
     * lettres ici pour ne pas se redécouvrir en lisant le code.
     */
    public function testATieAtMaxTurnsGoesToThePlayer(): void
    {
        $simulator = self::simulatorOf(maxTurns: 4);
        $player = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);
        $enemy = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, extraTurnPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(4, $outcome->turns);
        self::assertSame(BattleResult::Victory, $outcome->result);
    }

    /**
     * Si `max_turns` tombe juste après l'émission d'un `ExtraTurn`, la timeline ne doit
     * jamais se terminer par un tour bonus annoncé sans l'attaque qu'il promet — le client
     * jouerait « tour bonus ! » suivi de rien. `max_turns` est volontairement bas et
     * `extraTurnPermille` à 1000 pour forcer la boucle à sortir juste après un proc garanti.
     */
    public function testAnExtraTurnNeverDanglesWhenMaxTurnsIsReached(): void
    {
        $simulator = self::simulatorOf(maxTurns: 5);
        $player = self::fighterOf(hp: 100_000, damage: 1, mitigationPermille: 0, extraTurnPermille: 1000);
        $enemy = self::fighterOf(hp: 100_000, damage: 0, mitigationPermille: 0, extraTurnPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(5, $outcome->turns);

        $count = \count($outcome->timeline);
        self::assertInstanceOf(BattleFinished::class, $outcome->timeline[$count - 1]);
        self::assertInstanceOf(Attack::class, $outcome->timeline[$count - 2]);
    }

    /**
     * @param list<BattleEvent> $timeline
     */
    private static function firstAttack(array $timeline): Attack
    {
        foreach ($timeline as $event) {
            if ($event instanceof Attack) {
                return $event;
            }
        }

        self::fail('Aucune attaque dans la timeline.');
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private static function sortedDescending(array $values): array
    {
        $sorted = $values;
        rsort($sorted);

        return $sorted;
    }

    /**
     * `Xoshiro256StarStar` exige exactement 32 octets de graine — `sha256` en binaire en
     * rend toujours pile ce compte, quelle que soit la chaîne lisible passée par le test.
     */
    private static function randomizer(string $seed = 'seed-de-test'): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
    }

    private static function fighterOf(int $hp, int $damage, int $mitigationPermille, int $extraTurnPermille): Fighter
    {
        return new Fighter($hp, $damage, $mitigationPermille, $extraTurnPermille);
    }

    private static function simulatorOf(int $minimumDamage = 1, int $maxTurns = 200): BattleSimulator
    {
        return new BattleSimulator(new CombatRules(
            baseHp: 100,
            hpPer1000Vitality: 40,
            baseDamage: 10,
            damagePer1000Strength: 6,
            mitigationPermillePer1000Endurance: 15,
            mitigationCapPermille: 700,
            extraTurnPermillePer1000Dexterity: 12,
            extraTurnCapPermille: 350,
            minimumDamage: $minimumDamage,
            maxTurns: $maxTurns,
        ));
    }
}
