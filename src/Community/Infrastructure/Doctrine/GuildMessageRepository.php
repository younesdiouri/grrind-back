<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<GuildMessage> */
final class GuildMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildMessage::class);
    }

    public function replay(Guild $guild, Uuid $author, Uuid $client): ?GuildMessage
    {
        return $this->findOneBy(['guild' => $guild, 'authorId' => $author, 'clientId' => $client]);
    }

    public function nextPosition(Guild $guild): int
    {
        return ($this->findOneBy(['guild' => $guild], ['position' => 'DESC'])?->position() ?? 0) + 1;
    }

    public function add(GuildMessage $message): void
    {
        $this->getEntityManager()->persist($message);
    }

    /** @return list<GuildMessage> */
    public function page(Guild $guild, ?int $before, ?int $after, int $limit): array
    {
        $query = $this->createQueryBuilder('m')->where('m.guild = :guild')->setParameter('guild', $guild)
            ->orderBy('m.position', null === $after ? 'DESC' : 'ASC')->setMaxResults($limit);
        if (null !== $before) {
            $query->andWhere('m.position < :before')->setParameter('before', $before);
        }
        if (null !== $after) {
            $query->andWhere('m.position > :after')->setParameter('after', $after);
        }

        /** @var list<GuildMessage> $messages */
        $messages = $query->getQuery()->getResult();

        return $messages;
    }
}
