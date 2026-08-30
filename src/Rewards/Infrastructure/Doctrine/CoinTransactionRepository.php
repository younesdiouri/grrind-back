<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Doctrine;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\CoinTransaction;
use App\Rewards\Domain\Exception\InsufficientCoinBalance;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le ledger de pièces n'expose que deux gestes, même remarque qu'à
 * {@see \App\Progression\Infrastructure\Doctrine\XpTransactionRepository} : écrire, et
 * lire. Pas de `remove()`, pas d'`update()`.
 *
 * ## Le verrou : un compte, pas une ligne
 *
 * `Rewards` n'a pas de ligne façon `progression_snapshot` à verrouiller — c'est un choix du
 * ticket #225, pas un oubli : il n'y a rien à mettre en cache tant que personne ne le
 * mesure. Le geste de {@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository::lockFor()}
 * — verrouiller *avant* de lire, tenir jusqu'au COMMIT — reste le bon, mais `EntityManager::find()`
 * avec `LockMode::PESSIMISTIC_WRITE` a besoin d'une ligne à verrouiller.
 *
 * `pg_advisory_xact_lock` est la réponse de PostgreSQL à ce cas précis : un verrou
 * applicatif, porté par une clé qu'on choisit plutôt que par une ligne, relâché
 * automatiquement au COMMIT ou au ROLLBACK — pas de `release()` à ne pas oublier. Le
 * composant `Lock` de Symfony a été regardé avant d'écrire ceci et écarté : son
 * `PostgreSqlStore`/`DoctrineDbalPostgreSqlStore` s'appuie sur `pg_advisory_lock`, verrouillé
 * pour la **session**, pas la transaction — il exige un `release()` explicite et ne se
 * dénoue pas avec un ROLLBACK, exactement l'inverse de ce que cette méthode doit garantir.
 * `pg_advisory_xact_lock` n'a pas d'enveloppe Doctrine ni Symfony ; l'appeler en SQL brut
 * est le dernier recours documenté par la règle n°0 du projet, pas un raccourci.
 *
 * La clé est `hashtext(user_id)`, un entier 32 bits — pas l'UUID lui-même, que la fonction
 * n'accepte pas. Une collision entre deux joueurs différents ne corromprait rien : elle
 * ferait au pire attendre une écriture derrière une autre sans lien, jamais cohabiter deux
 * calculs de solde sur la même clé de verrou pour de mauvaises raisons — le pire cas est
 * une contention rare, jamais un solde faux.
 *
 * @extends ServiceEntityRepository<CoinTransaction>
 */
class CoinTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoinTransaction::class);
    }

    /**
     * Écrit une ligne au ledger, **sous verrou et sous garde**.
     *
     * L'ordre est celui de `GrantXpHandler` : verrouiller le compte, *puis* lire le solde —
     * sans quoi deux écritures concurrentes liraient le même solde de départ et
     * autoriseraient chacune une dépense que l'autre vient de rendre impossible. Le verrou
     * est posé une fois par appel ; un appelant qui écrirait plusieurs lignes dans la même
     * transaction le reprend sans effet supplémentaire — un verrou Postgres tenu par la
     * même transaction est ré-entrant, même remarque que sur le verrou de ligne du ledger
     * d'XP.
     *
     * **Aucune ligne négative n'existe encore (#225)** : `WORKOUT_DROP` et `BATTLE_DROP` ne
     * produisent que des montants positifs, pour lesquels `$balance + $amount < 0` ne peut
     * jamais être vrai. La garde s'applique malgré tout à *tout* appel, quel que soit le
     * signe — c'est elle que la boutique du Lot 6b traversera pour sa première dépense, sur
     * un chemin déjà éprouvé plutôt que sur une promesse.
     *
     * @throws InsufficientCoinBalance si la ligne ferait passer le solde sous zéro
     */
    public function record(Uuid $userId, CoinReason $reason, Uuid $sourceId, int $amount, DateTimeImmutable $occurredAt): CoinTransaction
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($userId, $reason, $sourceId, $amount, $occurredAt): CoinTransaction {
            $this->getEntityManager()->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:userId))',
                ['userId' => $userId->toRfc4122()],
            );

            $balance = $this->balanceOf($userId);

            if ($balance + $amount < 0) {
                throw new InsufficientCoinBalance($balance, $amount);
            }

            $transaction = CoinTransaction::record($userId, $reason, $sourceId, $amount, $occurredAt);
            $this->getEntityManager()->persist($transaction);
            $this->getEntityManager()->flush();

            return $transaction;
        });
    }

    /**
     * Le solde d'un joueur, par simple somme — c'est la définition du solde, voir le
     * docblock de {@see CoinTransaction}. Appelée sous le verrou par {@see record()}, elle
     * peut aussi l'être hors verrou pour une simple lecture d'affichage : une lecture qui ne
     * décide de rien n'a rien à sérialiser contre une écriture concurrente, même
     * raisonnement qu'à `ProgressionSnapshotRepository` pour `uncredited()`.
     */
    public function balanceOf(Uuid $userId): int
    {
        $total = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }
}
