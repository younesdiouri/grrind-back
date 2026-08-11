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
 * Lit par l'ORM, écrit en DBAL. Trois raisons, toutes contraignantes :
 *
 *  - **la réservation doit être atomique** — un `SELECT` puis un `INSERT` laisse passer
 *    deux requêtes concurrentes ; seul `INSERT … ON CONFLICT` ferme la fenêtre, et
 *    l'ORM ne sait pas l'exprimer ;
 *  - **un échec ne doit pas emporter l'EntityManager** — une violation de contrainte au
 *    `flush()` le ferme, et la requête métier qui suit ne pourrait plus rien écrire ;
 *  - **la libération doit survivre à un rollback**, donc s'écrire hors de l'unité de
 *    travail qui vient d'échouer.
 *
 * L'entité reste mappée : elle décrit le schéma et sert d'objet de lecture.
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
     * Rend l'identifiant de la réservation si elle nous revient, `null` si une autre
     * requête la tient déjà.
     *
     * Le `WHERE` de la clause de conflit fait le tri : une réservation vivante n'est pas
     * touchée (aucune ligne rendue), une périmée est recyclée sur place — sans quoi il
     * faudrait une purge avant de pouvoir réutiliser une clé.
     */
    public function claim(Uuid $userId, string $key, string $requestFingerprint, DateTimeImmutable $now): ?Uuid
    {
        // Composer l'INSERT depuis l'entité plutôt que recalculer ici : une seule
        // définition de la rétention.
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

    /** La réservation vivante que `claim()` a refusé de nous donner. */
    public function ofKey(Uuid $userId, string $key): ?IdempotencyRecord
    {
        return $this->findOneBy(['userId' => $userId, 'key' => $key]);
    }

    /**
     * Fige la réponse : tout rejeu de la même requête recevra celle-là, sans que le
     * contrôleur soit rappelé.
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
     * Une tentative qui a échoué n'est pas un résultat à rejouer : garder la clé
     * condamnerait le joueur à la même erreur pendant vingt-quatre heures.
     */
    public function release(Uuid $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_idempotency_key WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );
    }
}
