<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Guild;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
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
     * périmées. Le `refresh()` le garantit même si l'entité traînait déjà dans l'unité de
     * travail — ce qui est le cas courant ici, le code d'invitation ayant été lu en
     * premier et portant sa guilde.
     *
     * Le verrou porte sur *une ligne* : deux guildes différentes ne s'attendent jamais.
     *
     * @throws TransactionRequiredException hors transaction : un verrou qui se relâche aussitôt ne verrouille rien
     */
    public function lockForUpdate(Uuid $id): ?Guild
    {
        $guild = $this->find($id, LockMode::PESSIMISTIC_WRITE);

        if (null === $guild) {
            return null;
        }

        $this->getEntityManager()->refresh($guild);

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
        $this->getEntityManager()->remove($guild);
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
