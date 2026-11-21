<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\ApplicationStatus;
use App\Community\Domain\GuildApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<GuildApplication> */
final class GuildApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildApplication::class);
    }

    /** Toujours après le verrou de guilde, avant de lire les demandes ou l'appartenance. */
    public function lockPlayer(Uuid $player): void
    {
        $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT pg_advisory_xact_lock(hashtext('community.recruitment'), hashtext(?))",
            [$player->toRfc4122()],
        );
    }

    public function add(GuildApplication $application): void
    {
        $this->getEntityManager()->persist($application);
    }

    public function withdrawForPlayer(Uuid $player): void
    {
        $this->withdraw('playerId', $player);
    }

    public function withdrawForGuild(Uuid $guild): void
    {
        $this->withdraw('guildId', $guild);
    }

    private function withdraw(string $field, Uuid $id): void
    {
        $this->createQueryBuilder('a')->update()->set('a.status', ':withdrawn')
            ->where('a.'.$field.' = :id')->andWhere('a.status = :pending')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('pending', ApplicationStatus::Pending)
            ->setParameter('withdrawn', ApplicationStatus::Withdrawn)
            ->getQuery()->execute();
    }

    /** @return list<GuildApplication> */
    public function page(Uuid $owner, bool $inbox, ?string $cursor, int $size): array
    {
        $query = $this->createQueryBuilder('a')->where($inbox ? 'a.guildId = :owner' : 'a.playerId = :owner')
            ->setParameter('owner', $owner, UuidType::NAME)->orderBy('a.id', 'DESC')->setMaxResults($size);
        if ($inbox) {
            $query->andWhere('a.status = :pending')->setParameter('pending', ApplicationStatus::Pending);
        }
        if (null !== $cursor) {
            $query->andWhere('a.id < :cursor')->setParameter('cursor', Uuid::fromString($cursor), UuidType::NAME);
        }

        return $query->getQuery()->getResult();
    }

    /** Une admission dans une autre guilde peut avoir retiré cette demande pendant l'attente. */
    public function refresh(GuildApplication $application): void
    {
        $this->getEntityManager()->refresh($application);
    }
}
