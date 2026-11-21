<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Application\PublicGuild;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildMembership;
use App\Community\Domain\GuildApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Guild>
 */
class GuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guild::class);
    }

    public function add(Guild $guild): void
    {
        $this->getEntityManager()->persist($guild);
    }

    public function ofId(Uuid $id): ?Guild
    {
        return $this->find($id);
    }

    /**
     * La guilde, **verrouillée en écriture** jusqu'à la fin de la transaction.
     *
     * C'est le point de sérialisation de tout ce qui touche à sa composition : deux
     * joueurs qui prennent la dernière place au même instant, un départ pendant qu'un
     * autre arrive, deux générations de code d'invitation qui se croisent. Sans lui, les
     * deux requêtes comptent les mêmes membres, concluent la même chose et écrivent
     * toutes les deux — un `count()` relu juste avant l'`INSERT` ne change rien à
     * l'affaire, il déplace la fenêtre sans la fermer.
     *
     * **L'ordre compte** : le verrou se prend *avant* de toucher aux adhésions, sinon la
     * collection est chargée sur un état d'avant et le comptage porte sur des lignes
     * périmées. La relecture avec HINT_REFRESH recharge aussi les rôles des adhésions
     * déjà managées : rafraîchir la guilde seule laisse leur ancien rôle dans l'identity
     * map après une succession (#368). Elle constate aussi une dissolution pendant l'attente.
     *
     * Le verrou porte sur *une ligne* : deux guildes différentes ne s'attendent jamais.
     *
     * @throws TransactionRequiredException hors transaction : un verrou qui se relâche aussitôt ne verrouille rien
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/dql-doctrine-query-language.html#query-hints
     */
    public function lockForUpdate(Uuid $id): ?Guild
    {
        $guild = $this->find($id, LockMode::PESSIMISTIC_WRITE);

        if (null === $guild) {
            return null;
        }

        /** @var Guild|null $guild */
        $guild = $this->createQueryBuilder('g')
            ->addSelect('m')
            ->leftJoin('g.memberships', 'm')
            ->where('g.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $guild;
    }

    /**
     * Dissout la guilde. Les adhésions partent avec elle — `cascade: remove` sur
     * l'association, doublé du `ON DELETE CASCADE` de la colonne — et le tout dans la
     * transaction de l'appelant : une guilde supprimée dont les adhésions survivraient
     * laisserait ses membres dans une guilde qui n'existe plus, donc incapables d'en
     * rejoindre une autre à cause de l'index unique.
     */
    public function dissolve(Guild $guild): void
    {
        $this->getEntityManager()->getRepository(GuildApplication::class)->withdrawForGuild($guild->id());
        $this->getEntityManager()->remove($guild);
    }

    private function previews(): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->select('NEW '.PublicGuild::class.'(g.id, g.name, g.emblem.symbol, g.emblem.backgroundColor, g.emblem.symbolColor, g.recruitmentOpen, COUNT(m.id))')
            ->leftJoin('g.memberships', 'm')->groupBy('g.id');
    }

    /** @return list<PublicGuild> */
    public function directory(?string $cursor, int $size): array
    {
        $query = $this->previews()->where('g.recruitmentOpen = true')->orderBy('g.id', 'DESC')->setMaxResults($size);
        if (null !== $cursor) {
            $query->andWhere('g.id < :cursor')->setParameter('cursor', Uuid::fromString($cursor), UuidType::NAME);
        }

        return $query->getQuery()->getResult();
    }

    public function preview(Uuid $id, Uuid $player): ?PublicGuild
    {
        return $this->previews()->where('g.id = :id')
            ->andWhere('g.recruitmentOpen = true OR EXISTS (SELECT own.id FROM '.GuildMembership::class.' own WHERE own.guild = g AND own.playerId = :player)')
            ->setParameter('id', $id, UuidType::NAME)->setParameter('player', $player, UuidType::NAME)
            ->getQuery()->getOneOrNullResult();
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $work());
    }
}
