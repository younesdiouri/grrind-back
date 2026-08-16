<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La règle « un joueur n'appartient qu'à une guilde », prouvée là où elle est tenue.
 *
 * Elle ne peut pas se démontrer en test unitaire : une guilde ne voit pas les autres, et
 * le `if` qui manquerait à l'agrégat serait de toute façon le mauvais outil — entre le
 * `SELECT` qui vérifie et l'`INSERT` qui écrit, deux requêtes concurrentes passent toutes
 * les deux. C'est `uniq_community_membership_player` qui tranche, donc c'est contre une
 * vraie base qu'on l'écrit.
 *
 * Le test n'a ni route ni contrôleur : au #114, il n'y en a pas encore. Il s'adresse
 * directement au schéma, ce qui est exactement le niveau où vit la garantie.
 */
final class GuildMembershipUniquenessTest extends ApiTestCase
{
    public function testAPlayerCannotBelongToTwoGuilds(): void
    {
        $entityManager = self::entityManager();
        $player = Uuid::v7();

        $entityManager->persist(Guild::found('Les Lève-Tôt', $player, new DateTimeImmutable()));
        $entityManager->flush();

        // Une seconde guilde fondée par le même joueur : rien dans le code applicatif ne
        // l'en empêche à ce stade, et c'est le but — on veut voir la base refuser.
        $entityManager->persist(Guild::found('Les Couche-Tard', $player, new DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    public function testAPlayerCannotJoinASecondGuildEither(): void
    {
        $entityManager = self::entityManager();
        $player = Uuid::v7();

        $entityManager->persist(Guild::found('Les Lève-Tôt', $player, new DateTimeImmutable()));

        $other = Guild::found('Les Couche-Tard', Uuid::v7(), new DateTimeImmutable());
        $other->admit($player, new GuildRules(30, 48), new DateTimeImmutable());
        $entityManager->persist($other);

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    public function testDissolvingAGuildTakesItsMembershipsWithIt(): void
    {
        $entityManager = self::entityManager();
        $founder = Uuid::v7();
        $member = Uuid::v7();

        $guild = Guild::found('Les Lève-Tôt', $founder, new DateTimeImmutable());
        $guild->admit($member, new GuildRules(30, 48), new DateTimeImmutable());
        $entityManager->persist($guild);
        $entityManager->flush();

        $entityManager->remove($guild);
        $entityManager->flush();

        self::assertSame(0, self::countMemberships(), 'Une adhésion orpheline enfermerait son joueur hors de toute guilde.');

        // La preuve que ça compte : le joueur peut repartir ailleurs.
        $entityManager->persist(Guild::found('Les Couche-Tard', $member, new DateTimeImmutable()));
        $entityManager->flush();

        self::assertSame(1, self::countMemberships());
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function countMemberships(): int
    {
        $count = self::entityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM community_guild_membership');

        self::assertIsNumeric($count);

        return (int) $count;
    }
}
