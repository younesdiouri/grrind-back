<?php

declare(strict_types=1);

namespace App\Tests\Shared\Application;

use App\Shared\Application\ModifierContributor;
use App\Shared\Application\ModifierResolver;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * L'agrégation seule, sans conteneur : le resolver n'a pas d'autre travail, et ce qui se
 * démontre ici est indépendant de la liste des contributeurs réellement déployés.
 *
 * Que le tag soit correctement posé et injecté est une autre affirmation — elle se prouve
 * contre le vrai conteneur, dans {@see \App\Tests\Progression\GrantXpTest}.
 */
final class ModifierResolverTest extends TestCase
{
    public function testWithoutAnyContributorTheSetIsEmpty(): void
    {
        // L'état du Lot 3, et il doit rester un cas normal : le calcul d'XP tourne pour un
        // joueur qui n'a ni série, ni objet, ni compétence.
        self::assertSame([], new ModifierResolver([])->of(Uuid::v7()));
    }

    public function testAContributorWithNothingToSayIsNotAnError(): void
    {
        self::assertSame([], self::resolverOf(self::contributor())->of(Uuid::v7()));
    }

    public function testGathersTheContributionsOfEveryModule(): void
    {
        $streak = self::bonus(ModifierSource::Streak, 20);
        $boots = new Modifier(ModifierType::XpMultiplier, 15, ModifierSource::Item, Discipline::Running);
        $luck = new Modifier(ModifierType::LootLuck, 50, ModifierSource::Item);

        $resolved = self::resolverOf(
            self::contributor($streak),
            self::contributor($boots, $luck),
        )->of(Uuid::v7());

        // Rien n'est composé, filtré ni dédoublonné : décider ce qui s'applique est le
        // travail du consommateur, qui seul connaît sa discipline et le type qui l'intéresse.
        self::assertSame([$streak, $boots, $luck], $resolved);
    }

    public function testTheOrderComesFromTheSourcesAndNotFromTheContainer(): void
    {
        $streak = self::bonus(ModifierSource::Streak, 20);
        $skill = self::bonus(ModifierSource::Skill, 5);
        $item = self::bonus(ModifierSource::Item, 15);
        $league = self::bonus(ModifierSource::League, 10);

        // Le conteneur range les services tagués comme il veut ; le resolver, lui, rend
        // toujours la même suite. Sans ça, un ensemble résolu — donc un breakdown affiché
        // et un tirage de loot audité — dépendrait d'un ordre de compilation.
        $resolved = self::resolverOf(
            self::contributor($league),
            self::contributor($item),
            self::contributor($skill),
            self::contributor($streak),
        )->of(Uuid::v7());

        self::assertSame([$streak, $skill, $item, $league], $resolved);
    }

    public function testKeepsTheOrderAModuleGaveItsOwnModifiers(): void
    {
        // Deux objets équipés se suivent dans l'ordre où `Rewards` les a rendus : le tri
        // par source ne rebat pas les cartes à l'intérieur d'une source.
        $first = self::bonus(ModifierSource::Item, 15);
        $second = self::bonus(ModifierSource::Item, 5);

        self::assertSame([$first, $second], self::resolverOf(self::contributor($first, $second))->of(Uuid::v7()));
    }

    public function testAFailingContributorFailsTheWholeResolution(): void
    {
        // Le montant part au ledger, qui est append-only : un bonus avalé en silence se
        // paierait en XP manquante que plus rien ne rattrape. Mieux vaut refuser la séance.
        $this->expectException(RuntimeException::class);

        self::resolverOf(
            self::contributor(self::bonus(ModifierSource::Streak, 20)),
            new class implements ModifierContributor {
                public function modifiersOf(Uuid $userId): array
                {
                    throw new RuntimeException('la table des objets est injoignable');
                }
            },
        )->of(Uuid::v7());
    }

    public function testEachContributorIsAskedAboutThePlayerAtHand(): void
    {
        $player = Uuid::v7();
        $streak = self::bonus(ModifierSource::Streak, 20);

        // Le resolver ne met rien en cache : il transmet l'identifiant qu'on lui donne, à
        // chaque appel. Un ensemble mémorisé serait la meilleure façon de créditer un
        // joueur des bonus du précédent, et rien au ledger ne le dirait.
        $resolver = self::resolverOf(new class($player, $streak) implements ModifierContributor {
            public function __construct(private readonly Uuid $player, private readonly Modifier $streak)
            {
            }

            public function modifiersOf(Uuid $userId): array
            {
                return $userId->equals($this->player) ? [$this->streak] : [];
            }
        });

        self::assertSame([$streak], $resolver->of($player));
        self::assertSame([], $resolver->of(Uuid::v7()));
    }

    private static function resolverOf(ModifierContributor ...$contributors): ModifierResolver
    {
        return new ModifierResolver($contributors);
    }

    private static function contributor(Modifier ...$modifiers): ModifierContributor
    {
        return new class(array_values($modifiers)) implements ModifierContributor {
            /** @param list<Modifier> $modifiers */
            public function __construct(private readonly array $modifiers)
            {
            }

            public function modifiersOf(Uuid $userId): array
            {
                return $this->modifiers;
            }
        };
    }

    private static function bonus(ModifierSource $source, int $percentage): Modifier
    {
        return new Modifier(ModifierType::XpMultiplier, $percentage, $source);
    }
}
