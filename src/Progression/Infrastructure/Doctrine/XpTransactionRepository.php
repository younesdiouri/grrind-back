<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\PlayerRecord;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\LocalDay;
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

    /**
     * Ce que le joueur a déjà fait dans **sa** journée : le temps cumulé toutes disciplines
     * confondues, et l'XP déjà accordée dans celle-ci.
     *
     * Les deux portées diffèrent parce que les deux garde-fous ne visent pas la même chose
     * — le temps décroît sur le volume d'entraînement total, le plafond empêche de tout
     * concentrer sur la discipline la mieux payée.
     *
     * Une simple somme suffit à solder les annulations : leurs montants **et** leurs durées
     * sont négatifs, donc une séance invalidée s'annule d'elle-même dans les deux
     * compteurs, sans que la requête ait à filtrer sur les raisons.
     */
    public function dailyLoadOf(Uuid $userId, Discipline $discipline, LocalDay $day): DailyLoad
    {
        $row = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.durationSeconds), 0) AS seconds')
            // Le CASE porte la restriction à la discipline plutôt qu'un second appel :
            // une seule lecture de la journée, un seul parcours d'index.
            ->addSelect('COALESCE(SUM(CASE WHEN t.discipline = :discipline THEN t.amount ELSE 0 END), 0) AS xp')
            ->where('t.userId = :userId')
            ->andWhere('t.createdAt >= :startsAt')
            ->andWhere('t.createdAt < :endsAt')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('discipline', $discipline)
            ->setParameter('startsAt', $day->startsAt)
            ->setParameter('endsAt', $day->endsAt)
            ->getQuery()
            ->getSingleResult();

        \assert(\is_array($row) && is_numeric($row['seconds']) && is_numeric($row['xp']));

        // Un cumul négatif n'a pas de sens pour placer une séance sur la courbe : il
        // signifierait qu'on a annulé plus que ce qui avait été crédité ce jour-là, ce que
        // seule une annulation datée d'un autre jour peut produire.
        return new DailyLoad(max(0, (int) $row['seconds']), (int) $row['xp']);
    }

    /**
     * Le relevé du joueur, tel que les conditions de titre le lisent : son niveau, son XP,
     * et ce qu'il a fait discipline par discipline.
     *
     * **Tout se déduit du seul ledger**, y compris le niveau — la courbe le projette du
     * total, exactement comme pour le snapshot. Rien ici ne lit `progression_snapshot` :
     * un titre ne doit pas dépendre d'un cache, sans quoi un cache en retard ferait rater
     * un déblocage.
     *
     * La courbe entre en paramètre plutôt qu'en dépendance du dépôt — même geste qu'à
     * `ProgressionSnapshotRepository::lockFor()` : c'est l'appelant qui sait sous quel
     * équilibrage il projette.
     *
     * Une passe unique, groupée par discipline. Le compte de séances se somme comme le
     * reste : `+1` par crédit, `-1` par annulation, et une séance invalidée disparaît du
     * relevé sans que la requête ait à connaître les raisons.
     */
    public function recordOf(Uuid $userId, LevelCurve $curve): PlayerRecord
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.discipline AS discipline')
            ->addSelect('SUM(CASE WHEN t.reason = :completed THEN 1 ELSE -1 END) AS sessions')
            ->addSelect('SUM(t.durationSeconds) AS seconds')
            ->addSelect('SUM(t.amount) AS xp')
            ->where('t.userId = :userId')
            ->groupBy('t.discipline')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('completed', XpReason::SessionCompleted)
            ->getQuery()
            ->getScalarResult()
        ;

        $sessions = [];
        $seconds = [];
        $totalXp = 0;

        foreach ($rows as $row) {
            \assert(\is_array($row) && is_numeric($row['sessions']) && is_numeric($row['seconds']) && is_numeric($row['xp']));

            // Selon le mode d'hydratation, une colonne typée `enumType` rend l'enum ou sa
            // valeur brute. On normalise ici plutôt que de parier sur l'un des deux.
            \assert($row['discipline'] instanceof Discipline || \is_string($row['discipline']));
            $discipline = $row['discipline'] instanceof Discipline ? $row['discipline'] : Discipline::from($row['discipline']);

            // Des compteurs négatifs ne décrivent rien : ils signifieraient qu'on a annulé
            // plus de séances qu'il n'y en a eu. Le relevé plafonne à zéro, le ledger garde
            // le détail.
            $sessions[$discipline->value] = max(0, (int) $row['sessions']);
            $seconds[$discipline->value] = max(0, (int) $row['seconds']);
            $totalXp += (int) $row['xp'];
        }

        return new PlayerRecord($curve->standingAt($totalXp)->level, $totalXp, $sessions, $seconds);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
