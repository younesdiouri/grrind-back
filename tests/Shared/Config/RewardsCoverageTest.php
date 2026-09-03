<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Admin\Infrastructure\GameRulesetSeed;
use App\Combat\Application\FighterFactory;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\Fighter;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemKind;
use App\Rewards\Domain\LootTables;
use App\Shared\Application\ModifierContributor;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Les tables publiées depuis l'administration,
 * contre ce que le domaine exige réellement — même geste que `CombatCoverageTest` et
 * `AttributeSplitCoverageTest`.
 *
 * **C'est ici, et pas dans `ItemsSection` ni `LootSection`, que le vrai croisement entre
 * les trois catalogues DB se prouve** — voir le docblock de `LootTables` pour pourquoi
 * `GameBalanceLoader` ne peut pas le faire à la compilation de chaque section
 * indépendamment. Construire `LootTables` depuis les paramètres du conteneur, exactement
 * comme `services.yaml` le fait, est ce qui donne une vraie garantie : une clé d'objet ou
 * d'adversaire qui se serait glissée dans le catalogue DB de loot sans exister ailleurs fait échouer ce
 * test, pas une requête en production.
 */
final class RewardsCoverageTest extends KernelTestCase
{
    /**
     * Les quatre types dont la dérivation peut retomber à zéro sur un total trop petit —
     * voir le docblock du catalogue DB d'objets. Les cinq stats de combat directes n'y sont pas :
     * elles n'ont pas de coefficient à traverser.
     *
     * @var list<ModifierType>
     */
    private const array CHARACTERISTIC_BONUS_TYPES = [
        ModifierType::StrengthBonus,
        ModifierType::EnduranceBonus,
        ModifierType::MobilityBonus,
        ModifierType::DexterityBonus,
    ];

    public function testLeCatalogueDObjetsLivreConstruitSansErreur(): void
    {
        $catalog = self::shippedCatalog();

        self::assertNotCount(0, $catalog->all());
    }

    /**
     * Le point que ni `ItemsSection` ni `LootSection` ne peuvent prouver seules : chaque
     * entrée du catalogue DB de loot qui référence un objet référence un objet qui existe
     * réellement dans le catalogue DB d'objets — sinon cette construction aurait déjà jeté.
     */
    public function testLesTablesDeTirageLivreesConstruisentSansErreur(): void
    {
        $tables = self::shippedTables();

        self::assertNotCount(0, $tables->workoutTables());
    }

    /**
     * Chaque ennemi et chaque boss du catalogue DB a sa table de tirage : un adversaire
     * sans table ne ferait jamais tomber de récompense, un bug silencieux qu'aucune
     * exception ne signale — `forAdversary()` rend `null` en toute légitimité pour une clé
     * qui n'a simplement pas encore de table.
     */
    public function testChaqueAdversaireDuCatalogueALivreATableDeTirage(): void
    {
        $tables = self::shippedTables();
        $catalog = self::shippedEnemyCatalog();

        foreach ([...$catalog->all(), ...$catalog->bosses()] as $adversary) {
            self::assertNotNull($tables->forAdversary($adversary->key), \sprintf('"%s" n\'a pas de table de tirage dans le catalogue DB.', $adversary->key));
        }
    }

    /**
     * Le pendant du test précédent pour les coffres (#230) : un coffre sans table dédiée ne
     * s'ouvrirait jamais sur rien de tirable — `OpenChestHandler` le refuserait par une
     * `LogicException`, un bug de configuration que ce test attrape ici plutôt qu'à
     * l'ouverture d'un joueur. Le pendant inverse — un `EQUIPMENT` qui aurait une table de
     * coffre — n'a pas besoin de test dédié : `LootTables` le refuse déjà au chargement, voir
     * `LootTablesTest::testRefuseUneTableDeCoffrePourUneCleQuiNEstPasUnCoffre()`.
     */
    public function testChaqueCoffreDuCatalogueALivreATableDeTirage(): void
    {
        $tables = self::shippedTables();
        $catalog = self::shippedCatalog();

        foreach ($catalog->all() as $item) {
            if (ItemKind::Chest !== $item->kind) {
                continue;
            }

            self::assertNotNull($tables->forChest($item->key), \sprintf('"%s" n\'a pas de table de tirage dans le catalogue DB.', $item->key));
        }
    }

    public function testLaVersionDesTablesEstExposeeIndependammentDuRulesetVersion(): void
    {
        self::bootKernel();
        $ruleset = self::getContainer()->get(\App\Shared\Application\GameRulesets::class);
        self::assertInstanceOf(\App\Shared\Application\GameRulesets::class, $ruleset);
        /** @var array{loot: array{version: int}} $snapshot */
        $snapshot = $ruleset->snapshot();
        $seedLoot = GameRulesetSeed::data()['loot'];
        self::assertIsArray($seedLoot);
        self::assertSame(1, $seedLoot['version']);
        self::assertSame($snapshot['loot']['version'], self::shippedTables()->version());
        self::assertNotSame('', $ruleset->version());
    }

    /**
     * Le piège relevé en revue de la PR #232 : un `STRENGTH_BONUS` de 100 dérive à zéro
     * dégât (`intdiv(100 × 6, 1000) == 0`) — un objet qui n'apporte rigoureusement rien au
     * joueur, alors que le client affichera son intitulé sans qu'il ne change jamais un
     * combat. Voir le docblock de `FighterFactory` pour l'ordre de la dérivation, et celui
     * du catalogue DB d'objets pour pourquoi le seuil réel n'est pas figé là-bas mais prouvé ici.
     *
     * Chaque objet livré qui porte un bonus de caractéristique pure (`STRENGTH_BONUS`,
     * `ENDURANCE_BONUS`, `MOBILITY_BONUS`, `DEXTERITY_BONUS`) doit, équipé seul sur un
     * compte neuf, changer d'au moins un point la stat de combat qu'il dérive — celle de la
     * table du docblock de `FighterFactory`. Les stats de combat directes n'ont pas besoin
     * de cette garantie : elles n'ont pas de dérivation à rater, leur valeur est déjà
     * l'effet final.
     */
    public function testChaqueBonusDeCaracteristiquePureLivreChangeLeFighterDUnCompteNeuf(): void
    {
        $catalog = self::shippedCatalog();

        foreach ($catalog->all() as $item) {
            foreach ($item->modifiers as $modifier) {
                if (!\in_array($modifier->type, self::CHARACTERISTIC_BONUS_TYPES, true)) {
                    continue;
                }

                $baseline = self::shippedFighterFactory()->forPlayer(PlayerProgression::untouched(), Uuid::v7(), new DateTimeImmutable());
                $equipped = self::shippedFighterFactory([new Modifier($modifier->type, $modifier->value, ModifierSource::Item, $modifier->discipline)])
                    ->forPlayer(PlayerProgression::untouched(), Uuid::v7(), new DateTimeImmutable());

                self::assertNotSame(
                    self::derivedStatOf($baseline, $modifier->type),
                    self::derivedStatOf($equipped, $modifier->type),
                    \sprintf('"%s" porte un %s qui dérive à zéro sur un compte neuf — voir le docblock du catalogue DB.', $item->key, $modifier->type->value),
                );
            }
        }
    }

    /**
     * `ItemsSection` ne borne `price_coins` qu'à `>= 0` (#27) : un objet gratuit reste une
     * config valide pour un drop, mais vendre du vide n'a aucun sens à l'étal (#229). C'est
     * une garantie qui croise le catalogue et la boutique — le genre que cette classe existe
     * pour prouver, pas `ItemsSection` ni `ItemCatalog` isolément.
     */
    public function testChaqueObjetAVenteAUnPrixStrictementPositif(): void
    {
        $catalog = self::shippedCatalog();

        foreach ($catalog->shopItems() as $item) {
            self::assertGreaterThan(0, $item->priceCoins, \sprintf('"%s" est à l\'étal avec un prix nul ou négatif.', $item->key));
        }
    }

    private static function shippedCatalog(): ItemCatalog
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(ItemCatalog::class);
        self::assertInstanceOf(ItemCatalog::class, $catalog);

        return $catalog;
    }

    private static function shippedTables(): LootTables
    {
        self::bootKernel();
        $tables = self::getContainer()->get(LootTables::class);
        self::assertInstanceOf(LootTables::class, $tables);

        return $tables;
    }

    private static function shippedEnemyCatalog(): EnemyCatalog
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(EnemyCatalog::class);
        self::assertInstanceOf(EnemyCatalog::class, $catalog);

        return $catalog;
    }

    /**
     * Une `FighterFactory` construite sur les vraies règles du catalogue DB, avec un
     * resolver qui force les modificateurs passés — jamais un vrai contributeur, aucun
     * n'existe encore (#224) — même geste que `FighterFactoryTest`.
     *
     * @param list<Modifier> $modifiers
     */
    private static function shippedFighterFactory(array $modifiers = []): FighterFactory
    {
        self::bootKernel();
        /** @var array{combat: array{fighter: array{base_hp: int, hp_per_1000_vitality: int, base_damage: int, damage_per_1000_strength: int, mitigation_permille_per_1000_endurance: int, mitigation_cap_permille: int, extra_turn_permille_per_1000_dexterity: int, extra_turn_cap_permille: int, dodge_permille_per_1000_mobility: int, dodge_cap_permille: int, minimum_damage: int, max_turns: int}}} $snapshot */
        $snapshot = self::getContainer()->get(\App\Shared\Application\GameRulesets::class)->snapshot();
        $fighter = $snapshot['combat']['fighter'];
        self::assertIsArray($fighter);
        $rules = new CombatRules(
            $fighter['base_hp'],
            $fighter['hp_per_1000_vitality'],
            $fighter['base_damage'],
            $fighter['damage_per_1000_strength'],
            $fighter['mitigation_permille_per_1000_endurance'],
            $fighter['mitigation_cap_permille'],
            $fighter['extra_turn_permille_per_1000_dexterity'],
            $fighter['extra_turn_cap_permille'],
            $fighter['dodge_permille_per_1000_mobility'],
            $fighter['dodge_cap_permille'],
            $fighter['minimum_damage'],
            $fighter['max_turns'],
        );

        return new FighterFactory($rules, new ModifierResolver([
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
        ]));
    }

    /**
     * La stat de `Fighter` que dérive chaque type de caractéristique pure — la même table
     * que le docblock de `FighterFactory`.
     */
    private static function derivedStatOf(Fighter $fighter, ModifierType $type): int
    {
        return match ($type) {
            ModifierType::StrengthBonus => $fighter->damage,
            ModifierType::EnduranceBonus => $fighter->mitigationPermille,
            ModifierType::MobilityBonus => $fighter->dodgePermille,
            ModifierType::DexterityBonus => $fighter->extraTurnPermille,
            default => throw new LogicException(\sprintf('"%s" n\'est pas un bonus de caractéristique pure.', $type->value)),
        };
    }
}
