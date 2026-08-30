<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Combat\Application\FighterFactory;
use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
use DateTimeImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La table livrée, celle de `config/game/v1/combat.yaml`, contre ce que le domaine exige
 * réellement — même geste qu'`AttributeSplitCoverageTest` pour la répartition d'XP. Que la
 * compilation ait abouti prouve déjà que `CombatSection` a validé les socles et le
 * catalogue sans broncher ; ce test vérifie ce qu'ils valent, construits depuis les
 * paramètres du conteneur comme `services.yaml` le fait réellement.
 *
 * **Les deux derniers tests sont de l'équilibrage, pas de la formule** (relevé en revue du
 * #208, corrigé au #210). Ils rejouent un vrai combat, avec `FighterFactory` et
 * `BattleSimulator`, sur les valeurs *réellement livrées* dans `combat.yaml` — pas sur des
 * valeurs de confort choisies dans le test. Ils sont censés tomber à chaque retouche du
 * fichier qui déséquilibre à nouveau un des deux bouts de la courbe : ce n'est pas un test
 * fragile à supprimer si ça arrive, c'est exactement ce qu'on lui demande de détecter.
 */
final class CombatCoverageTest extends KernelTestCase
{
    public function testExposesTheFighterCoefficientsAsTheirOwnParameters(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame(140, $container->getParameter('game.combat.fighter.base_hp'));
        self::assertSame(200, $container->getParameter('game.combat.fighter.max_turns'));
    }

    public function testTheShippedFighterCoefficientsAreCoherent(): void
    {
        // Que la construction n'ait pas jeté prouve déjà la cohérence ; on vérifie ici
        // qu'elle porte bien les valeurs livrées.
        $rules = self::shippedRules();

        self::assertLessThan(1000, $rules->mitigationCapPermille);
        self::assertLessThan(1000, $rules->extraTurnCapPermille);
        self::assertGreaterThanOrEqual(1, $rules->minimumDamage);
    }

    public function testTheShippedCatalogueCoversTheLevelOneEncounter(): void
    {
        $catalog = self::shippedCatalog();

        $starter = $catalog->forLevel(1);

        self::assertSame(1, $starter->level);
        self::assertGreaterThan(0, $starter->hp);
    }

    public function testTheShippedCatalogueHasNoDuplicateLevel(): void
    {
        $enemies = self::getContainer()->getParameter('game.combat.enemies');
        self::assertIsArray($enemies);

        $levels = [];
        foreach ($enemies as $enemy) {
            self::assertIsArray($enemy);
            $levels[] = $enemy['level'];
        }

        self::assertSame($levels, array_unique($levels), 'Le catalogue livré porte deux ennemis pour le même niveau.');
    }

    /**
     * Le cas relevé en revue du #208 : un compte neuf — les quatre caractéristiques et la
     * Vitality à zéro, {@see PlayerProgression::untouched()} — contre `forLevel(1)`. Avant
     * le relevé du socle au #210, le calcul à la main donnait un joueur qui perd, largement
     * (14 attaques pour passer 120 PV contre 9 pour l'ennemi). Rejoué ici sur plusieurs
     * graines : le compte neuf doit gagner, pas juste une fois par chance.
     */
    public function testTheShippedFighterSocleWinsTheLevelOneEncounter(): void
    {
        $factory = self::shippedFactory();
        $simulator = self::shippedSimulator();
        $catalog = self::shippedCatalog();

        $player = $factory->forPlayer(PlayerProgression::untouched(), Uuid::v7(), new DateTimeImmutable());
        $enemy = $factory->forEnemy($catalog->forLevel(1));

        foreach (['fraiche-1', 'fraiche-2', 'fraiche-3', 'fraiche-4', 'fraiche-5'] as $seed) {
            $outcome = $simulator->fight($player, $enemy, self::randomizer($seed));

            self::assertSame(
                BattleResult::Victory,
                $outcome->result,
                \sprintf('Le niveau 1 doit rester gagnable pour un compte neuf (graine "%s").', $seed),
            );
        }
    }

    /**
     * Le second cas relevé en revue du #208 : les caractéristiques d'un joueur sont
     * linéaires en XP, et `total_xp` est quadratique en niveau — un joueur de haut niveau
     * dépasse donc le catalogue de plus en plus largement si celui-ci ne suit pas. Le
     * joueur simulé ici est le plus favorable que la formule puisse produire à ce niveau : à
     * XP totale égale, une répartition parfaitement équilibrée maximise la Vitality (le
     * rapport moyenne géométrique / moyenne arithmétique de {@see
     * \App\Shared\Domain\Activity\Vitality} vaut 1 quand les quatre totaux sont égaux) —
     * voir le calcul dans `combat.yaml`. Sur plusieurs graines, cet adversaire doit rester
     * capable de gagner : une victoire garantie à 100 % serait une formalité, pas un combat.
     */
    public function testTheShippedTopTierEncounterIsNotAFormality(): void
    {
        $factory = self::shippedFactory();
        $simulator = self::shippedSimulator();
        $catalog = self::shippedCatalog();

        // Niveau 50, 75 460 XP au total (`levels.yaml`), répartis à parts égales sur les
        // quatre caractéristiques : le coefficient de Vitality est alors maximal (1000 ‰),
        // donc `vitality = total` — voir le docblock de `Vitality`.
        $totalXp = 75_460;
        $perAttribute = intdiv($totalXp, 4);

        $veteran = new PlayerProgression(
            level: 50,
            xpIntoLevel: 0,
            xpToNextLevel: null,
            title: null,
            attributes: new AttributeGains($perAttribute, $perAttribute, $perAttribute, $perAttribute),
            vitality: $totalXp,
            vitalityBreakdown: new VitalityBreakdown(0, 1, 0),
        );

        $player = $factory->forPlayer($veteran, Uuid::v7(), new DateTimeImmutable());
        $enemy = $factory->forEnemy($catalog->forLevel(50));

        $results = [];
        foreach (range(1, 12) as $i) {
            $results[] = $simulator->fight($player, $enemy, self::randomizer("veteran-{$i}"))->result;
        }

        self::assertContains(
            BattleResult::Victory,
            $results,
            'Le haut niveau doit rester gagnable : ce n\'est pas une punition non plus.',
        );
        self::assertContains(
            BattleResult::Defeat,
            $results,
            'Le haut niveau ne doit pas être une formalité : une victoire garantie sur toutes les graines montre que le catalogue ne suit plus le joueur.',
        );
    }

    /**
     * Un test de fumée, pas un test d'équilibrage : il ne juge aucune difficulté, il vérifie
     * seulement qu'aucun palier livré n'est cassé — un combat qui se termine, qui rend un
     * vainqueur, où les deux camps portent au moins un coup. C'est ce qui manquait pour les
     * quatre paliers intermédiaires que #210 n'a délibérément pas retouchés — voir la note
     * dans `combat.yaml`, au-dessus du catalogue, pour pourquoi ils restent tels quels en
     * attendant une vraie partie.
     */
    public function testEveryShippedTierProducesAStandingFight(): void
    {
        $factory = self::shippedFactory();
        $simulator = self::shippedSimulator();
        $catalog = self::shippedCatalog();

        foreach ($catalog->all() as $tier) {
            self::assertStandingFight($factory, $simulator, $tier, "palier-{$tier->level}");
        }
    }

    /**
     * Le même test de fumée, étendu aux boss (#219) : chacun produit un combat qui se
     * termine, rend un vainqueur, et où les deux camps portent au moins un coup — rien de
     * plus, voir le docblock de la classe pour ce qui reste hors de son périmètre. Le joueur
     * simulé est pile au `minimum_level` du boss, le cas le plus défavorable qu'un combat
     * légitime puisse rencontrer.
     */
    public function testEveryShippedBossProducesAStandingFight(): void
    {
        $factory = self::shippedFactory();
        $simulator = self::shippedSimulator();
        $catalog = self::shippedCatalog();

        foreach ($catalog->bosses() as $boss) {
            self::assertStandingFight($factory, $simulator, $boss, "boss-{$boss->key}");
        }
    }

    /**
     * Le corps commun aux deux tests de fumée ci-dessus (#219) : un joueur simulé pile au
     * niveau de l'adversaire — le palier pour un ennemi ordinaire, `minimum_level` pour un
     * boss, `Enemy::$level` porte les deux, voir son docblock — doit produire un combat qui
     * se termine, rend un vainqueur, où les deux camps portent au moins un coup. Extraite
     * une fois `all()` et `bosses()` en partage exactement le même corps, seule la source de
     * la liste change.
     */
    private static function assertStandingFight(FighterFactory $factory, BattleSimulator $simulator, Enemy $tier, string $seed): void
    {
        $totalXp = self::totalXpAtLevel($tier->level, $tier->key);
        $perAttribute = intdiv($totalXp, 4);

        $challenger = new PlayerProgression(
            level: $tier->level,
            xpIntoLevel: 0,
            xpToNextLevel: null,
            title: null,
            attributes: new AttributeGains($perAttribute, $perAttribute, $perAttribute, $perAttribute),
            vitality: $totalXp,
            vitalityBreakdown: new VitalityBreakdown(0, 1, 0),
        );

        $player = $factory->forPlayer($challenger, Uuid::v7(), new DateTimeImmutable());
        $enemy = $factory->forEnemy($tier);

        $outcome = $simulator->fight($player, $enemy, self::randomizer($seed));

        $playerLandedAHit = false;
        $enemyLandedAHit = false;

        foreach ($outcome->timeline as $event) {
            if (!$event instanceof Attack) {
                continue;
            }

            if (Actor::Player === $event->attacker) {
                $playerLandedAHit = true;
            } else {
                $enemyLandedAHit = true;
            }
        }

        self::assertInstanceOf(BattleResult::class, $outcome->result, \sprintf('"%s" (niveau %d) doit rendre un vainqueur.', $tier->key, $tier->level));
        self::assertTrue($playerLandedAHit, \sprintf('"%s" (niveau %d) ne doit pas laisser le joueur sans porter un seul coup.', $tier->key, $tier->level));
        self::assertTrue($enemyLandedAHit, \sprintf('"%s" (niveau %d) ne doit pas laisser l\'adversaire sans porter un seul coup.', $tier->key, $tier->level));
    }

    /**
     * Le total d'XP au seuil de chaque palier (`levels.yaml`) — même table pour les ennemis
     * ordinaires et les boss, puisque les `minimum_level` livrés dans `combat.yaml`
     * recoupent volontairement des paliers déjà couverts ici, voir le commentaire au-dessus
     * du bloc `bosses:`. Une clé de plus dans l'un des deux catalogues sans entrée ici fait
     * échouer ce test plutôt que de laisser passer un adversaire non vérifié en silence.
     */
    private static function totalXpAtLevel(int $level, string $key): int
    {
        $totalXpByLevel = [1 => 0, 5 => 760, 10 => 3_060, 20 => 12_160, 30 => 27_260, 50 => 75_460];

        self::assertArrayHasKey(
            $level,
            $totalXpByLevel,
            \sprintf('Niveau %d ("%s") sans total d\'XP de référence dans ce test.', $level, $key),
        );

        return $totalXpByLevel[$level];
    }

    /**
     * `Xoshiro256StarStar` exige exactement 32 octets de graine — `sha256` en binaire en
     * rend toujours pile ce compte, quelle que soit la chaîne lisible passée par le test.
     * Même geste que {@see \App\Tests\Combat\Domain\BattleSimulatorTest::randomizer()}.
     */
    private static function randomizer(string $seed): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
    }

    private static function shippedFactory(): FighterFactory
    {
        self::bootKernel();
        $container = self::getContainer();

        // Le vrai `ModifierResolver` du conteneur : aucun contributeur ne branche encore
        // de modificateur (#224), donc il rend un ensemble vide, exactement ce que ce
        // fichier de combat pur doit voir.
        $modifiers = $container->get(ModifierResolver::class);
        self::assertInstanceOf(ModifierResolver::class, $modifiers);

        return new FighterFactory(self::shippedRules(), $modifiers);
    }

    private static function shippedSimulator(): BattleSimulator
    {
        return new BattleSimulator(self::shippedRules());
    }

    private static function shippedRules(): CombatRules
    {
        self::bootKernel();
        $container = self::getContainer();

        return new CombatRules(
            self::intParameter($container, 'game.combat.fighter.base_hp'),
            self::intParameter($container, 'game.combat.fighter.hp_per_1000_vitality'),
            self::intParameter($container, 'game.combat.fighter.base_damage'),
            self::intParameter($container, 'game.combat.fighter.damage_per_1000_strength'),
            self::intParameter($container, 'game.combat.fighter.mitigation_permille_per_1000_endurance'),
            self::intParameter($container, 'game.combat.fighter.mitigation_cap_permille'),
            self::intParameter($container, 'game.combat.fighter.extra_turn_permille_per_1000_dexterity'),
            self::intParameter($container, 'game.combat.fighter.extra_turn_cap_permille'),
            self::intParameter($container, 'game.combat.fighter.dodge_permille_per_1000_mobility'),
            self::intParameter($container, 'game.combat.fighter.dodge_cap_permille'),
            self::intParameter($container, 'game.combat.fighter.minimum_damage'),
            self::intParameter($container, 'game.combat.fighter.max_turns'),
        );
    }

    private static function shippedCatalog(): EnemyCatalog
    {
        self::bootKernel();
        $container = self::getContainer();

        $enemies = $container->getParameter('game.combat.enemies');
        self::assertIsArray($enemies);

        $bosses = $container->getParameter('game.combat.bosses');
        self::assertIsArray($bosses);

        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $enemies */
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses */
        return new EnemyCatalog($enemies, $bosses);
    }

    private static function intParameter(ContainerInterface $container, string $name): int
    {
        $value = $container->getParameter($name);
        self::assertIsInt($value);

        return $value;
    }
}
