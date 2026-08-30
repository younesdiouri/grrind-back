<?php

declare(strict_types=1);

namespace App\Tests\Combat\Application;

use App\Combat\Application\FighterFactory;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Shared\Application\ModifierContributor;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La traduction caractéristique → combattant, seule et sans conteneur : voir le docblock de
 * {@see FighterFactory} pour la correspondance et pourquoi le socle + contribution est la
 * forme retenue. Ce que ces tests démontrent est indépendant des valeurs livrées dans
 * `combat.yaml` — {@see \App\Tests\Shared\Config\CombatCoverageTest} vérifie celles-là.
 *
 * **Aucun objet, aucun contributeur réel (#224).** {@see modifiersOf()} force des
 * `Modifier` à la main — le seul moyen d'éprouver l'ordre de la dérivation avant que
 * `Rewards` ne branche l'inventaire sur le port (#29). Les tests qui ne passent aucun
 * modificateur prouvent le branchement : le `ModifierResolver` rend un ensemble vide et le
 * combattant est celui d'avant #224, au bit près.
 */
final class FighterFactoryTest extends TestCase
{
    public function testAFreshAccountIsPlayableAtTheFighterSocle(): void
    {
        $factory = self::factoryOf();

        $fighter = $factory->forPlayer(PlayerProgression::untouched(), self::playerId(), self::occurredAt());

        // Les quatre caractéristiques et la Vitality valent zéro pour un compte neuf : le
        // combattant produit est exactement le socle, pas un combattant à zéro point de vie.
        self::assertSame(100, $fighter->hp);
        self::assertSame(10, $fighter->damage);
        self::assertSame(0, $fighter->mitigationPermille);
        self::assertSame(0, $fighter->extraTurnPermille);
        self::assertSame(0, $fighter->dodgePermille);
    }

    public function testMoreVitalityYieldsMoreHpEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(vitality: 1_000), self::playerId(), self::occurredAt());
        $high = $factory->forPlayer(self::progressionOf(vitality: 5_000), self::playerId(), self::occurredAt());

        self::assertGreaterThan($low->hp, $high->hp);
    }

    public function testMoreStrengthYieldsMoreDamageEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(strength: 1_000), self::playerId(), self::occurredAt());
        $high = $factory->forPlayer(self::progressionOf(strength: 5_000), self::playerId(), self::occurredAt());

        self::assertGreaterThan($low->damage, $high->damage);
    }

    public function testMoreEnduranceYieldsMoreMitigationEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(endurance: 1_000), self::playerId(), self::occurredAt());
        $high = $factory->forPlayer(self::progressionOf(endurance: 5_000), self::playerId(), self::occurredAt());

        self::assertGreaterThan($low->mitigationPermille, $high->mitigationPermille);
    }

    public function testMoreDexterityYieldsMoreExtraTurnChanceEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(dexterity: 1_000), self::playerId(), self::occurredAt());
        $high = $factory->forPlayer(self::progressionOf(dexterity: 5_000), self::playerId(), self::occurredAt());

        self::assertGreaterThan($low->extraTurnPermille, $high->extraTurnPermille);
    }

    /**
     * `Mobility` entre en combat au #218 — voir le docblock de {@see FighterFactory}. Ce
     * test était l'inverse jusque-là (#210) : il prouvait que la caractéristique ne changeait
     * rien au `Fighter` produit, pour qu'elle ne soit pas branchée par distraction avant
     * qu'on ait décidé à quoi. La décision est prise ; c'est maintenant l'inverse qui doit
     * être vrai, et cette classe reste le seul endroit qui en parle.
     */
    public function testMobilityChangesTheFighterProduced(): void
    {
        $factory = self::factoryOf();

        $withoutMobility = $factory->forPlayer(self::progressionOf(strength: 2_000, endurance: 2_000, dexterity: 2_000, mobility: 0), self::playerId(), self::occurredAt());
        $withMobility = $factory->forPlayer(self::progressionOf(strength: 2_000, endurance: 2_000, dexterity: 2_000, mobility: 1_000_000), self::playerId(), self::occurredAt());

        self::assertNotEquals($withoutMobility, $withMobility);
        self::assertGreaterThan($withoutMobility->dodgePermille, $withMobility->dodgePermille);
    }

    public function testMoreMobilityYieldsMoreDodgeChanceEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(mobility: 1_000), self::playerId(), self::occurredAt());
        $high = $factory->forPlayer(self::progressionOf(mobility: 5_000), self::playerId(), self::occurredAt());

        self::assertGreaterThan($low->dodgePermille, $high->dodgePermille);
    }

    public function testDodgeChanceNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(dodgeCapPermille: 300);

        $fighter = $factory->forPlayer(self::progressionOf(mobility: 1_000_000), self::playerId(), self::occurredAt());

        self::assertSame(300, $fighter->dodgePermille);
    }

    public function testMitigationNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(mitigationCapPermille: 700);

        $fighter = $factory->forPlayer(self::progressionOf(endurance: 1_000_000), self::playerId(), self::occurredAt());

        self::assertSame(700, $fighter->mitigationPermille);
    }

    public function testExtraTurnChanceNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(extraTurnCapPermille: 350);

        $fighter = $factory->forPlayer(self::progressionOf(dexterity: 1_000_000), self::playerId(), self::occurredAt());

        self::assertSame(350, $fighter->extraTurnPermille);
    }

    /**
     * Le catalogue écrit déjà des valeurs de combattant : `forEnemy()` ne dérive rien, il
     * transporte — voir le docblock d'`Enemy`.
     */
    public function testAnEnemyMapsDirectlyOntoAFighter(): void
    {
        $factory = self::factoryOf();

        $enemy = new Enemy(key: 'SAND_JACKAL', level: 1, hp: 120, damage: 12, mitigationPermille: 50, extraTurnPermille: 40, dodgePermille: 30);

        $fighter = $factory->forEnemy($enemy);

        self::assertSame(120, $fighter->hp);
        self::assertSame(12, $fighter->damage);
        self::assertSame(50, $fighter->mitigationPermille);
        self::assertSame(40, $fighter->extraTurnPermille);
        self::assertSame(30, $fighter->dodgePermille);
    }

    /**
     * Un bonus de caractéristique pure s'ajoute au total lu du snapshot **avant** la
     * dérivation, et non au résultat de la dérivation (#224) : `+1000` Strength avec un
     * coefficient de 6 pour 1000 vaut `+6` dégâts, exactement comme si le joueur avait
     * réparti ce total lui-même.
     */
    public function testACharacteristicBonusTraversesTheDerivation(): void
    {
        $factory = self::factoryOf(modifiers: [self::modifierOf(ModifierType::StrengthBonus, 1_000)]);

        $fighter = $factory->forPlayer(self::progressionOf(), self::playerId(), self::occurredAt());

        self::assertSame(16, $fighter->damage);
    }

    /**
     * Un bonus de stat directe s'ajoute **après** la dérivation, sans passer par une
     * caractéristique (#224) : un `HP_BONUS` de +50 donne exactement le socle +50, que la
     * Vitality du joueur soit nulle ou non.
     */
    public function testADirectStatBonusAppliesAfterDerivation(): void
    {
        $factory = self::factoryOf(modifiers: [self::modifierOf(ModifierType::HpBonus, 50)]);

        $fighter = $factory->forPlayer(self::progressionOf(vitality: 1_000), self::playerId(), self::occurredAt());

        // Socle 100 + scale(1000, 40) = 140, plus le bonus direct de 50.
        self::assertSame(190, $fighter->hp);
    }

    /**
     * Deux modificateurs du même type se composent par la somme (#224) : le choix est
     * tranché dans le docblock de {@see FighterFactory}. Deux objets « +100 » et « +50 »
     * Strength valent un seul « +150 », pas un « +100 » qui écraserait l'autre.
     */
    public function testTwoModifiersOfTheSameTypeSumUp(): void
    {
        $withBoth = self::factoryOf(modifiers: [
            self::modifierOf(ModifierType::StrengthBonus, 100),
            self::modifierOf(ModifierType::StrengthBonus, 50),
        ])->forPlayer(self::progressionOf(), self::playerId(), self::occurredAt());

        $withSum = self::factoryOf(modifiers: [self::modifierOf(ModifierType::StrengthBonus, 150)])
            ->forPlayer(self::progressionOf(), self::playerId(), self::occurredAt());

        self::assertSame($withSum->damage, $withBoth->damage);
    }

    /**
     * Un objet ne franchit jamais un plafond (#224) : un `MITIGATION_BONUS` volontairement
     * démesuré reste plafonné à `mitigation_cap_permille`, exactement comme la dérivation
     * pure l'était déjà avant ce ticket.
     */
    public function testAnItemCannotCrossAConfiguredCap(): void
    {
        $factory = self::factoryOf(mitigationCapPermille: 700, modifiers: [self::modifierOf(ModifierType::MitigationBonus, 1_000_000)]);

        $fighter = $factory->forPlayer(self::progressionOf(endurance: 1_000_000), self::playerId(), self::occurredAt());

        self::assertSame(700, $fighter->mitigationPermille);
    }

    /**
     * Le plancher de `Fighter` se pose dans la factory, pas dans son constructeur (#224) :
     * un `HP_BONUS` très négatif ne fait pas échouer la dérivation, il la ramène à un point
     * de vie — le minimum que `Fighter` accepte.
     */
    public function testANegativeBonusDoesNotBreakTheFighterFloor(): void
    {
        $factory = self::factoryOf(modifiers: [self::modifierOf(ModifierType::HpBonus, -1_000_000)]);

        $fighter = $factory->forPlayer(self::progressionOf(vitality: 1_000), self::playerId(), self::occurredAt());

        self::assertSame(1, $fighter->hp);
    }

    /**
     * `forEnemy()` ne consulte aucun modificateur (#224) : même avec des modificateurs
     * configurés sur le resolver de la factory, un ennemi du catalogue reste transporté tel
     * quel — voir le docblock de la classe pour pourquoi cette asymétrie est délibérée.
     */
    public function testAnEnemyReceivesNoModifier(): void
    {
        $factory = self::factoryOf(modifiers: [self::modifierOf(ModifierType::HpBonus, 999_999)]);

        $enemy = new Enemy(key: 'SAND_JACKAL', level: 1, hp: 120, damage: 12, mitigationPermille: 50, extraTurnPermille: 40, dodgePermille: 30);

        $fighter = $factory->forEnemy($enemy);

        self::assertSame(120, $fighter->hp);
    }

    /**
     * @param list<Modifier> $modifiers forcés à la main — voir le docblock de la classe
     */
    private static function factoryOf(
        int $mitigationCapPermille = 700,
        int $extraTurnCapPermille = 350,
        int $dodgeCapPermille = 300,
        array $modifiers = [],
    ): FighterFactory {
        return new FighterFactory(
            new CombatRules(
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
                minimumDamage: 1,
                maxTurns: 200,
            ),
            new ModifierResolver([
                new class($modifiers) implements ModifierContributor {
                    /**
                     * @param list<Modifier> $modifiers
                     */
                    public function __construct(private array $modifiers)
                    {
                    }

                    public function modifiersOf(Uuid $userId, DateTimeImmutable $occurredAt): array
                    {
                        return $this->modifiers;
                    }
                },
            ]),
        );
    }

    private static function progressionOf(
        int $strength = 0,
        int $endurance = 0,
        int $mobility = 0,
        int $dexterity = 0,
        int $vitality = 0,
    ): PlayerProgression {
        return new PlayerProgression(
            level: 1,
            xpIntoLevel: 0,
            xpToNextLevel: null,
            title: null,
            attributes: new AttributeGains($strength, $endurance, $mobility, $dexterity),
            vitality: $vitality,
            vitalityBreakdown: new VitalityBreakdown(0, 1, 0),
        );
    }

    private static function modifierOf(ModifierType $type, int $value): Modifier
    {
        return new Modifier($type, $value, ModifierSource::Item);
    }

    private static function playerId(): Uuid
    {
        return Uuid::v7();
    }

    private static function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
