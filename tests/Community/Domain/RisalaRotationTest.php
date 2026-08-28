<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\RisalaRotation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La règle de rotation seule, sans base ni horloge : « tant que tout le monde n'a pas envoyé
 * la sienne, on ne peut pas être tiré deux fois ».
 *
 * Elle se teste par table de cas parce qu'elle est pure — c'est tout l'intérêt d'avoir sorti
 * le hasard de la classe. Ce qui reste au handler est un `random_int` sur un vivier déjà
 * constitué, et il n'y a rien à démontrer là-dessus.
 */
final class RisalaRotationTest extends TestCase
{
    public function testEveryoneIsEligibleWhenNobodyHasSentYet(): void
    {
        $members = self::members(3);

        $rotation = new RisalaRotation($members, [], 0);

        self::assertSame(0, $rotation->cycle);
        self::assertCount(3, $rotation->pool);
    }

    public function testSomeoneWhoAlreadySentIsOutOfTheDraw(): void
    {
        [$anna, $bob, $carla] = self::members(3);

        $rotation = new RisalaRotation([$anna, $bob, $carla], [$bob], 0);

        // Bob n'est plus tirable, et c'est la seule chose que la rotation garantit : pas
        // l'équité en moyenne — un tirage uniforme l'aurait déjà — mais l'équité à court
        // terme, celle qui empêche quelqu'un d'envoyer trois fois avant le dernier.
        self::assertSame([$anna, $carla], $rotation->pool);
        self::assertSame(0, $rotation->cycle);
    }

    public function testACompletedCycleStartsTheNextOneAndEverybodyIsBack(): void
    {
        $members = self::members(3);

        $rotation = new RisalaRotation($members, $members, 4);

        // La seule façon dont un cycle se termine. Sans ça, une guilde dont tout le monde a
        // envoyé n'aurait plus personne à tirer et la mécanique s'arrêterait au bout d'un
        // tour — la panne se verrait des semaines plus tard, comme un silence.
        self::assertSame(5, $rotation->cycle);
        self::assertCount(3, $rotation->pool);
    }

    public function testAMemberWhoJoinedMidCycleIsEligibleRightAway(): void
    {
        [$anna, $bob] = self::members(2);
        $newcomer = Uuid::v7();

        $rotation = new RisalaRotation([$anna, $bob, $newcomer], [$anna, $bob], 2);

        // Le cycle n'est pas bouclé : il reste quelqu'un qui n'a jamais envoyé. Faire
        // attendre le nouveau venu jusqu'au cycle suivant serait la plus mauvaise façon de
        // l'accueillir — il rejoint une guilde pour jouer avec elle, pas pour la regarder.
        self::assertSame(2, $rotation->cycle);
        self::assertSame([$newcomer], $rotation->pool);
    }

    public function testAMemberWhoLeftNoLongerHoldsTheCycleOpen(): void
    {
        [$anna, $bob] = self::members(2);
        $departed = Uuid::v7();

        // `$departed` n'a jamais envoyé, mais il n'est plus membre : le cycle se termine sans
        // lui plutôt que de l'attendre indéfiniment.
        $rotation = new RisalaRotation([$anna, $bob], [$anna, $bob], 1);

        self::assertSame(2, $rotation->cycle);
        self::assertNotContains($departed, $rotation->pool);
    }

    public function testTheDeparturesOfPastCyclesDoNotDisturbTheCurrentOne(): void
    {
        [$anna, $bob] = self::members(2);
        $ghost = Uuid::v7();

        // Un expéditeur du cycle courant qui n'est plus membre : il ne rend personne
        // inéligible, il ne se retrouve pas dans le vivier, il est simplement ignoré.
        $rotation = new RisalaRotation([$anna, $bob], [$ghost], 3);

        self::assertSame([$anna, $bob], $rotation->pool);
    }

    public function testThePoolIsOrderedSoThatADrawCanBeReplayed(): void
    {
        $members = self::members(4);
        $shuffled = [$members[2], $members[0], $members[3], $members[1]];

        // Le rang tiré et la taille du vivier sont écrits sur la Risāla. Rejouer un tirage
        // exige de retrouver le même ordre, et un ordre rendu par la base changerait au
        // premier `VACUUM` — la trace ne voudrait alors plus rien dire.
        self::assertSame(
            new RisalaRotation($members, [], 0)->pool,
            new RisalaRotation($shuffled, [], 0)->pool,
        );
    }

    public function testARollOutsideThePoolIsARefusalAndNotAWrapAround(): void
    {
        $rotation = new RisalaRotation(self::members(2), [], 0);

        // Un modulo silencieux ferait tomber le tirage sur quelqu'un tout de même, et la
        // trace écrite serait fausse sans que personne le sache.
        $this->expectException(InvalidArgumentException::class);

        $rotation->drawnBy(2);
    }

    /**
     * Des identifiants croissants, comme les UUID v7 réels : l'ordre du vivier en dépend.
     *
     * @return non-empty-list<Uuid>
     */
    private static function members(int $count): array
    {
        $members = [];

        for ($i = 0; $i < $count; ++$i) {
            $members[] = Uuid::v7();
        }

        \assert([] !== $members);

        return $members;
    }
}
