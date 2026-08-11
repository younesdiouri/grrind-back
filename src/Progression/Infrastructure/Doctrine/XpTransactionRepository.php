<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le ledger n'expose que deux gestes : écrire, et lire. Pas de `remove()`, pas de
 * `update()` — les absents comptent autant que les présents dans une table append-only.
 *
 * @extends ServiceEntityRepository<XpTransaction>
 */
class XpTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, XpTransaction::class);
    }

    /** Les lignes suivent par la cascade : une transaction s'écrit d'un bloc ou pas du tout. */
    public function add(XpTransaction $transaction): void
    {
        $this->getEntityManager()->persist($transaction);
    }

    /**
     * Le total d'un joueur, par simple somme. C'est la définition du solde : le snapshot
     * (#16) n'en est qu'un cache, et c'est cette requête qui fait autorité quand les deux
     * divergent — c'est aussi elle que la commande de reconstruction (#20) rejouera.
     */
    public function totalOf(Uuid $userId): int
    {
        $total = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }

    /**
     * L'écriture déjà produite par cette source, s'il y en a une. Sert autant à retrouver
     * le crédit qu'une invalidation doit annuler qu'à ne pas créditer deux fois la même
     * séance — mais ce n'est pas ce qui *garantit* l'idempotence : entre cette lecture et
     * l'écriture, deux requêtes rejouées passent toutes les deux. C'est
     * `uniq_xp_transaction_source_reason` qui tranche, et cette méthode qui rend le cas
     * courant lisible.
     */
    public function recordedFor(Uuid $sourceId, XpReason $reason): ?XpTransaction
    {
        return $this->findOneBy(['sourceId' => $sourceId, 'reason' => $reason]);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
