<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\RecordStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Le dépôt des clés d'idempotence. Il lit par l'ORM et écrit en DBAL, et ce n'est pas
 * une coquetterie :
 *
 *  - **La réservation doit être atomique.** Deux requêtes concurrentes portant la même
 *    clé arrivent en même temps ; il faut qu'une seule reparte avec le droit d'écrire.
 *    Un `SELECT` puis un `INSERT` laisse la fenêtre ouverte ; seul le `INSERT … ON
 *    CONFLICT` de PostgreSQL la ferme, et l'ORM ne sait pas l'exprimer.
 *  - **Un échec ne doit pas emporter l'EntityManager.** En ORM, une violation de
 *    contrainte au `flush()` ferme l'EntityManager : la requête métier qui suit ne
 *    pourrait plus rien écrire. En DBAL, la collision est une valeur de retour.
 *  - **La libération doit survivre à un rollback.** Quand la transaction métier casse,
 *    il faut effacer la réservation pour que le client puisse réessayer — donc écrire
 *    en dehors de l'unité de travail qui vient d'échouer.
 *
 * L'entité, elle, reste mappée : c'est elle qui décrit le schéma, alimente
 * `doctrine:migrations:diff` et sert d'objet de lecture.
 *
 * @extends ServiceEntityRepository<IdempotencyRecord>
 */
class IdempotencyRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyRecord::class);
    }

    /**
     * Réserve la clé pour cette requête. Rend l'identifiant de la réservation si elle
     * nous revient, `null` si une autre requête la tient déjà — à l'appelant, alors,
     * de lire ce qu'elle est devenue.
     *
     * Le `WHERE` sur la clause de conflit fait le tri : une réservation encore vivante
     * n'est pas touchée (aucune ligne rendue), une réservation périmée est recyclée sur
     * place. C'est ce qui évite d'avoir à purger avant de pouvoir réutiliser une clé.
     */
    public function claim(Uuid $userId, string $key, string $requestFingerprint, DateTimeImmutable $now): ?Uuid
    {
        // L'entité décide de l'identifiant et de la péremption ; le dépôt ne fait que
        // les écrire. Composer l'INSERT à partir de ses valeurs plutôt que de les
        // recalculer ici évite d'avoir deux définitions de la rétention.
        $reservation = IdempotencyRecord::reserve($userId, $key, $requestFingerprint, $now);

        $claimed = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                INSERT INTO shared_idempotency_key
                    (id, user_id, idempotency_key, request_fingerprint, status, created_at, expires_at)
                VALUES (:id, :userId, :key, :fingerprint, :status, :now, :expiresAt)
                ON CONFLICT (user_id, idempotency_key) DO UPDATE SET
                    request_fingerprint = EXCLUDED.request_fingerprint,
                    status              = EXCLUDED.status,
                    response_status     = NULL,
                    response_headers    = NULL,
                    response_body       = NULL,
                    created_at          = EXCLUDED.created_at,
                    expires_at          = EXCLUDED.expires_at
                WHERE shared_idempotency_key.expires_at <= EXCLUDED.created_at
                RETURNING id
                SQL,
            [
                'id' => $reservation->id()->toRfc4122(),
                'userId' => $reservation->userId()->toRfc4122(),
                'key' => $reservation->key(),
                'fingerprint' => $reservation->requestFingerprint(),
                'status' => $reservation->status()->value,
                'now' => $reservation->createdAt(),
                'expiresAt' => $reservation->expiresAt(),
            ],
            [
                'now' => Types::DATETIMETZ_IMMUTABLE,
                'expiresAt' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return \is_string($claimed) ? Uuid::fromString($claimed) : null;
    }

    /**
     * La réservation vivante que `claim()` a refusé de nous donner.
     */
    public function ofKey(Uuid $userId, string $key): ?IdempotencyRecord
    {
        return $this->findOneBy(['userId' => $userId, 'key' => $key]);
    }

    /**
     * Fige la réponse : à partir d'ici, tout rejeu de la même requête recevra celle-là,
     * à l'identique, sans que le contrôleur soit rappelé.
     *
     * @param array<string, string> $headers
     */
    public function complete(Uuid $id, int $status, array $headers, string $body): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE shared_idempotency_key
                SET status = :status, response_status = :responseStatus,
                    response_headers = :headers, response_body = :body
                WHERE id = :id
                SQL,
            [
                'status' => RecordStatus::Completed->value,
                'responseStatus' => $status,
                'headers' => $headers,
                'body' => $body,
                'id' => $id->toRfc4122(),
            ],
            ['headers' => Types::JSON],
        );
    }

    /**
     * Rend la clé au client. Une tentative qui a échoué n'est pas un résultat à rejouer :
     * la garder condamnerait le joueur à recevoir la même erreur pendant vingt-quatre
     * heures, sur une action qui, elle, n'a rien écrit.
     */
    public function release(Uuid $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_idempotency_key WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );
    }
}
