<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\CombatRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * L'équilibrage se valide au démarrage, pas à la première requête d'un joueur : c'est cette
 * classe que {@see \App\Combat\Infrastructure\Config\CombatSection} fait rejouer à la
 * compilation du conteneur. Chacun des quatre refus rend la terminaison du combat
 * démontrable au #209 et au #218 — voir le docblock de {@see CombatRules}.
 */
final class CombatRulesTest extends TestCase
{
    public function testAcceptsAUsableSetOfCoefficients(): void
    {
        $rules = self::rulesOf();

        self::assertSame(100, $rules->baseHp);
        self::assertSame(700, $rules->mitigationCapPermille);
        self::assertSame(1, $rules->minimumDamage);
        self::assertSame(350, $rules->extraTurnCapPermille);
        self::assertSame(200, $rules->maxTurns);
    }

    /**
     * À 1000 millièmes (100 %) de mitigation, un combattant devient invulnérable : plus
     * rien ne fait jamais baisser un point de vie.
     */
    public function testRefusesAMitigationCapThatReachesInvulnerability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rulesOf(mitigationCapPermille: 1000);
    }

    public function testRefusesAMitigationCapThatExceedsInvulnerability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rulesOf(mitigationCapPermille: 1500);
    }

    /**
     * Même conséquence, par un autre chemin : un dégât plancher de zéro laisserait un tour
     * sans aucun effet.
     */
    public function testRefusesAMinimumDamageBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rulesOf(minimumDamage: 0);
    }

    /**
     * À 1000 millièmes de chance de tour supplémentaire, le joueur ne rendrait jamais la
     * main : le combat ne se terminerait plus par lui-même.
     */
    public function testRefusesAnExtraTurnCapThatReachesCertainty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rulesOf(extraTurnCapPermille: 1000);
    }

    /**
     * À 1000 millièmes de chance d'esquive, une cible n'encaisserait plus jamais rien : le
     * combat ne se déciderait plus sur ses propres mérites, seul `maxTurns` l'arrêterait —
     * voir le docblock de {@see \App\Combat\Domain\BattleSimulator} pour ce que ça change à
     * sa démonstration de terminaison (#218).
     */
    public function testRefusesADodgeCapThatReachesCertainty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rulesOf(dodgeCapPermille: 1000);
    }

    private static function rulesOf(
        int $mitigationCapPermille = 700,
        int $minimumDamage = 1,
        int $extraTurnCapPermille = 350,
        int $dodgeCapPermille = 300,
    ): CombatRules {
        return new CombatRules(
            baseHp: 100,
            hpPer1000Vitality: 40,
            baseDamage: 10,
            damagePer1000Strength: 6,
            mitigationPermillePer1000Endurance: 15,
            mitigationCapPermille: $mitigationCapPermille,
            extraTurnPermillePer1000Dexterity: 12,
            extraTurnCapPermille: $extraTurnCapPermille,
            dodgePermillePer1000Mobility: 10,
            dodgeCapPermille: $dodgeCapPermille,
            minimumDamage: $minimumDamage,
            maxTurns: 200,
        );
    }
}
