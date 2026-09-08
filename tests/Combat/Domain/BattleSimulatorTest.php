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
use App\Combat\Domain\Combo;
use App\Combat\Domain\Dodge;
use App\Combat\Domain\Fighter;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le moteur seul, sans base ni horloge : des `Fighter` et un `Randomizer` entrent, une
 * timeline sort. Ce qui se démontre ici n'est pas « ça marche sur un exemple », c'est que
 * la boucle **termine toujours** — voir le docblock de {@see BattleSimulator} pour ce que
 * l'esquive (#218) a changé à cette démonstration, `max_attacks` en étant désormais le seul
 * garant dur — et que la timeline qu'elle produit est le contrat d'animation qu'elle
 * prétend être.
 */
final class BattleSimulatorTest extends TestCase
{
    public function testHigherDamageKillsInFewerAttacks(): void
    {
        $simulator = self::simulatorOf();
        $enemy = self::fighterOf(hp: 100, damage: 0, mitigationPermille: 0, comboPermille: 0);

        $strong = self::fighterOf(hp: 500, damage: 50, mitigationPermille: 0, comboPermille: 0);
        $weak = self::fighterOf(hp: 500, damage: 10, mitigationPermille: 0, comboPermille: 0);

        $attacksToKill = static fn (Fighter $player): int => \count(array_filter(
            $simulator->fight($player, $enemy, self::randomizer())->timeline,
            static fn (BattleEvent $event): bool => $event instanceof Attack && Actor::Player === $event->attacker,
        ));

        self::assertLessThan($attacksToKill($weak), $attacksToKill($strong));
    }

    public function testHigherMitigationReducesDamageTakenAndRespectsTheCap(): void
    {
        $simulator = self::simulatorOf();
        $attacker = self::fighterOf(hp: 1000, damage: 100, mitigationPermille: 0, comboPermille: 0);

        $undefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, comboPermille: 0);
        // 699 : juste sous le plafond de 700 que `CombatRules` impose à la dérivation
        // (#210) — ce ticket-ci ne dérive rien, mais la valeur qu'il force ici est celle
        // que la config autorise réellement.
        $heavilyDefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 699, comboPermille: 0);

        $firstHitOn = static fn (Fighter $defender): int => self::firstAttack(
            $simulator->fight($attacker, $defender, self::randomizer())->timeline,
        )->damage;

        self::assertSame(100, $firstHitOn($undefended));
        self::assertLessThan($firstHitOn($undefended), $firstHitOn($heavilyDefended));
        // floor(100 * (1000 - 699) / 1000) = 30 : la mitigation s'applique, mais
        // reste sous le dégât brut, jamais jusqu'à l'invulnérabilité.
        self::assertSame(30, $firstHitOn($heavilyDefended));
    }

    public function testAnComboPermilleOfOneThousandAlwaysProcs(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 50);

        // L'ennemi encaisse sans jamais mourir avant que le test ait pu observer plusieurs
        // tours supplémentaires d'affilée ; il ne riposte jamais, donc si le joueur ne
        // procait pas systématiquement, la main lui reviendrait et un `Attack` de l'ennemi
        // apparaîtrait dans la timeline.
        $player = self::fighterOf(hp: 100, damage: 1, mitigationPermille: 0, comboPermille: 1000);
        $enemy = self::fighterOf(hp: 100_000, damage: 0, mitigationPermille: 0, comboPermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        $enemyAttacks = array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Attack && Actor::Enemy === $event->attacker);
        $combos = array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Combo);

        self::assertSame([], array_values($enemyAttacks));
        self::assertNotSame([], array_values($combos));

        foreach ($combos as $combo) {
            self::assertSame(Actor::Player, $combo->actor);
        }
    }

    public function testAnComboPermilleOfZeroNeverProcs(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0);
        $enemy = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        self::assertSame([], array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Combo)));

        // Sans jamais rejouer, la main alterne strictement : joueur, ennemi, joueur, ...
        $attackers = array_map(
            static fn (Attack $attack): Actor => $attack->attacker,
            array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Attack)),
        );
        self::assertSame(Actor::Player, $attackers[0]);
        self::assertSame(Actor::Enemy, $attackers[1]);
        self::assertSame(Actor::Enemy, $attackers[2]);
    }

    /**
     * `dodgePermille` à 1000 (100 %) est refusé par `CombatRules`, mais {@see Fighter} ne
     * le réapplique pas — voir son docblock — précisément pour que ce test puisse forcer la
     * valeur : une cible qui esquive toujours n'encaisse jamais rien, et le combat ne se
     * termine plus que par `max_attacks`, devenu le seul garant dur (#218).
     */
    public function testADodgePermilleOfOneThousandAlwaysDodgesAndTheBattleEndsByMaxTurns(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 10);
        // Dégât nul des deux côtés : le seul dégât qui peut jamais s'appliquer vient du
        // plancher (`minimum_damage`), jamais assez pour achever qui que ce soit avant
        // `max_attacks` sur un point de vie aussi haut.
        $player = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, comboPermille: 0);
        $enemy = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, comboPermille: 0, dodgePermille: 1000);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(10, $outcome->attackCount);
        self::assertSame(
            [],
            array_values(array_filter($outcome->timeline, static fn (BattleEvent $event): bool => $event instanceof Attack && Actor::Player === $event->attacker)),
            'Une cible qui esquive toujours ne doit jamais encaisser une attaque.',
        );
    }

    public function testADodgePermilleOfZeroNeverAppearsInTheTimeline(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 200, damage: 15, mitigationPermille: 0, comboPermille: 0, dodgePermille: 0);
        $enemy = self::fighterOf(hp: 200, damage: 14, mitigationPermille: 0, comboPermille: 0, dodgePermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        self::assertSame([], array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Dodge)));
    }

    /**
     * Le piège le plus facile à écrire à l'envers : le jet d'esquive se joue sur la mobilité
     * de la CIBLE, pas de l'attaquant. Le joueur, très évasif, ne dodge jamais **ses
     * propres** attaques — il les porte contre un ennemi qui n'esquive pas — mais évite
     * systématiquement celles de l'ennemi.
     */
    public function testDodgeIsRolledOnTheTargetsMobilityNotTheAttackers(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 6);
        $player = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0, dodgePermille: 1000);
        $enemy = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0, dodgePermille: 0);

        $timeline = $simulator->fight($player, $enemy, self::randomizer())->timeline;

        $attacks = array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Attack));
        $dodges = array_values(array_filter($timeline, static fn (BattleEvent $event): bool => $event instanceof Dodge));

        self::assertNotSame([], $attacks);
        self::assertNotSame([], $dodges);

        // Le joueur (dodge 1000) porte tous les coups qui atterrissent : sa cible, l'ennemi,
        // n'esquive jamais (dodge 0).
        foreach ($attacks as $attack) {
            self::assertSame(Actor::Player, $attack->attacker);
        }

        // L'ennemi (dodge 0) ne rate jamais sa cible par sa propre faute : c'est le joueur,
        // en face, qui esquive systématiquement grâce à sa propre mobilité.
        foreach ($dodges as $dodge) {
            self::assertSame(Actor::Enemy, $dodge->attacker);
        }
    }

    /**
     * Un tour esquivé ne retire aucun point de vie : les PV restants relevés sur les
     * `Attack` de la timeline — les seuls événements qui en portent — ne remontent jamais,
     * y compris entrecoupés d'esquives.
     */
    public function testADodgedTurnRemovesNoHitPointAndHpNeverIncreases(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 30);
        $player = self::fighterOf(hp: 500, damage: 20, mitigationPermille: 0, comboPermille: 0, dodgePermille: 500);
        $enemy = self::fighterOf(hp: 500, damage: 20, mitigationPermille: 0, comboPermille: 0, dodgePermille: 500);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        $dodges = array_values(array_filter($outcome->timeline, static fn (BattleEvent $event): bool => $event instanceof Dodge));
        self::assertNotSame([], $dodges, 'Le scénario doit produire au moins une esquive pour être un test utile.');

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

        self::assertSame($enemyHpTrail, self::sortedDescending($enemyHpTrail));
        self::assertSame($playerHpTrail, self::sortedDescending($playerHpTrail));
    }

    public function testAttackReportsTheAmountAbsorbedByMitigation(): void
    {
        $simulator = self::simulatorOf();
        $attacker = self::fighterOf(hp: 1000, damage: 100, mitigationPermille: 0, comboPermille: 0);

        $undefended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 0, comboPermille: 0);
        // 699 : juste sous le plafond de 700 que `CombatRules` impose à la dérivation (#210).
        $defended = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 699, comboPermille: 0);

        $attackOn = static fn (Fighter $defender): Attack => self::firstAttack(
            $simulator->fight($attacker, $defender, self::randomizer())->timeline,
        );

        $undefendedHit = $attackOn($undefended);
        self::assertSame(0, $undefendedHit->mitigated);
        self::assertSame($attacker->damage, $undefendedHit->damage + $undefendedHit->mitigated);

        $defendedHit = $attackOn($defended);
        // floor(100 * (1000 - 699) / 1000) = 30 : l'absorbé est la différence
        // exacte entre le dégât brut et le dégât porté, tant que le plancher ne mord pas.
        self::assertSame(70, $defendedHit->mitigated);
        self::assertSame(30, $defendedHit->damage);
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
        $attacker = self::fighterOf(hp: 1000, damage: 4, mitigationPermille: 0, comboPermille: 0);
        $defender = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 999, comboPermille: 0);

        $hit = self::firstAttack($simulator->fight($attacker, $defender, self::randomizer())->timeline);

        // reduction = 4 - floor(4 * (1000 - 999) / 1000) = 4, donc un dégât porté de 4 - 3 = 1 sans
        // plancher ; le plancher le remonte à 3, mais `mitigated` reste 4.
        self::assertSame(4, $hit->mitigated);
        self::assertSame(3, $hit->damage);
        self::assertGreaterThan($attacker->damage, $hit->damage + $hit->mitigated);
    }

    public function testDamageNeverGoesBelowMinimumDamageEvenAgainstHeavyMitigation(): void
    {
        $simulator = self::simulatorOf(minimumDamage: 3);
        // Un dégât brut faible face à une mitigation lourde : sans plancher, la formule
        // rendrait un dégât nul et la cible ne mourrait jamais.
        $attacker = self::fighterOf(hp: 1000, damage: 4, mitigationPermille: 0, comboPermille: 0);
        $defender = self::fighterOf(hp: 1000, damage: 0, mitigationPermille: 999, comboPermille: 0);

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
        $player = self::fighterOf(hp: 100, damage: 15, mitigationPermille: 50, comboPermille: 200);
        $enemy = self::fighterOf(hp: 120, damage: 12, mitigationPermille: 60, comboPermille: 150);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertInstanceOf(BattleResult::class, $outcome->result);
        $last = $outcome->timeline[\count($outcome->timeline) - 1];
        self::assertInstanceOf(BattleFinished::class, $last);
        self::assertSame($outcome->result, $last->result);
    }

    public function testTheSameSeedProducesIdenticalTimelines(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 200, damage: 15, mitigationPermille: 50, comboPermille: 300);
        $enemy = self::fighterOf(hp: 200, damage: 14, mitigationPermille: 40, comboPermille: 250);

        $first = $simulator->fight($player, $enemy, self::randomizer('même graine'));
        $second = $simulator->fight($player, $enemy, self::randomizer('même graine'));

        self::assertEquals($first, $second);
    }

    public function testTheTimelineIsInternallyConsistent(): void
    {
        $simulator = self::simulatorOf();
        $player = self::fighterOf(hp: 150, damage: 20, mitigationPermille: 30, comboPermille: 200);
        $enemy = self::fighterOf(hp: 140, damage: 18, mitigationPermille: 20, comboPermille: 150);

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

        // KO normal (pas de `max_attacks` ici) : l'un des deux trails finit à zéro, et
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
        // `max_attacks`, pas par un KO, et doit tout de même rendre un vainqueur.
        $simulator = self::simulatorOf(maxAttacks: 3);
        $player = self::fighterOf(hp: 10_000, damage: 5, mitigationPermille: 0, comboPermille: 0);
        $enemy = self::fighterOf(hp: 10_000, damage: 5, mitigationPermille: 0, comboPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(3, $outcome->attackCount);
        self::assertInstanceOf(BattleResult::class, $outcome->result);

        // Aucun KO ici : personne n'atteint zéro, la sortie vient bien de `max_attacks`.
        foreach ($outcome->timeline as $event) {
            if ($event instanceof Attack) {
                self::assertGreaterThan(0, $event->targetHpRemaining);
            }
        }
    }

    /**
     * À `max_attacks`, le joueur joue en premier (voir le docblock de la classe) : sur un
     * nombre pair de tours et des combattants strictement symétriques, les deux camps
     * infligent exactement les mêmes dégâts. Une égalité stricte de ratio doit se
     * départager, et c'est le joueur qui l'emporte — un choix de ce ticket, écrit en toutes
     * lettres ici pour ne pas se redécouvrir en lisant le code.
     */
    public function testATieAtMaxTurnsGoesToThePlayer(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 4);
        $player = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0);
        $enemy = self::fighterOf(hp: 1000, damage: 5, mitigationPermille: 0, comboPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(4, $outcome->attackCount);
        self::assertSame(BattleResult::Victory, $outcome->result);
    }

    /**
     * Si `max_attacks` tombe juste après l'émission d'un `Combo`, la timeline ne doit
     * jamais se terminer par un tour bonus annoncé sans l'attaque qu'il promet — le client
     * jouerait « tour bonus ! » suivi de rien. `max_attacks` est volontairement bas et
     * `comboPermille` à 1000 pour forcer la boucle à sortir juste après un proc garanti.
     */
    public function testAnComboNeverDanglesWhenMaxTurnsIsReached(): void
    {
        $simulator = self::simulatorOf(maxAttacks: 5);
        $player = self::fighterOf(hp: 100_000, damage: 1, mitigationPermille: 0, comboPermille: 1000);
        $enemy = self::fighterOf(hp: 100_000, damage: 0, mitigationPermille: 0, comboPermille: 0);

        $outcome = $simulator->fight($player, $enemy, self::randomizer());

        self::assertSame(5, $outcome->attackCount);

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

    private static function fighterOf(int $hp, int $damage, int $mitigationPermille, int $comboPermille, int $dodgePermille = 0): Fighter
    {
        return new Fighter($hp, $damage, $mitigationPermille, $comboPermille, $dodgePermille);
    }

    private static function simulatorOf(int $minimumDamage = 1, int $maxAttacks = 200): BattleSimulator
    {
        return new BattleSimulator(new CombatRules(
            baseHp: 100,
            hpPer1000Vitality: 40,
            baseDamage: 10,
            damagePer1000Strength: 6,
            mitigationCapPermille: 700,
            comboCapPermille: 350,
            dodgeCapPermille: 300,
            minimumDamage: $minimumDamage,
            maxAttacks: $maxAttacks,
            fatigueFloorPermille: 400,
            fatigueCapacity: 4000,
            criticalMultiplierPermille: 1500,
            baseCooldownTicks: 1000,
            maintenanceCapPermille: 950,
            maintenanceHalfSaturation: 5000,
            dodgeHalfSaturation: 10000,
            criticalChanceCapPermille: 350,
            criticalChanceHalfSaturation: 10000,
            mitigationHalfSaturation: 10000,
            guardCapPermille: 400,
            guardHalfSaturation: 10000,
            criticalResistanceCapPermille: 500,
            criticalResistanceHalfSaturation: 10000,
            cooldownReductionCapPermille: 300,
            cooldownReductionHalfSaturation: 10000,
            comboHalfSaturation: 10000,
            precisionCapPermille: 500,
            precisionHalfSaturation: 10000,
        ));
    }
}
