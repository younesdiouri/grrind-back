<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyInFlight;
use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\RecordStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;
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
     * La réservation se sérialise sans attente, séparément de l'action. On lit les
     * clés existantes avant tout INSERT (même DO NOTHING attendrait leur COMMIT),
     * et le recyclage saute les lignes verrouillées. Un nouvel UUID empêche l'ancien
     * détenteur expiré de compléter ou libérer la réservation qui l'a remplacé.
     */
    public function claim(Uuid $userId, string $key, string $requestFingerprint, DateTimeImmutable $now): ?Uuid
    {
        // Composer l'INSERT depuis l'entité plutôt que recalculer ici : une seule
        // définition de la rétention.
        $reservation = IdempotencyRecord::reserve($userId, $key, $requestFingerprint, $now);

        return $this->getEntityManager()->getConnection()->transactional(static function (Connection $connection) use ($reservation): ?Uuid {
            // L'unicité SQL peut attendre un reçu non commis, même avec DO NOTHING.
            // Ce verrou non bloquant ne couvre que la réservation, jamais l'action.
            if (!$connection->fetchOne("SELECT pg_try_advisory_xact_lock(hashtext('shared.idempotency'), hashtext(?))", [$reservation->userId()->toRfc4122().':'.$reservation->key()])) {
                throw new IdempotencyKeyInFlight($reservation->key());
            }
            $parameters = [
                'id' => $reservation->id()->toRfc4122(),
                'userId' => $reservation->userId()->toRfc4122(),
                'key' => $reservation->key(),
                'fingerprint' => $reservation->requestFingerprint(),
                'status' => $reservation->status()->value,
                'now' => $reservation->createdAt(),
                'expiresAt' => $reservation->expiresAt(),
            ];
            $types = ['now' => Types::DATETIMETZ_IMMUTABLE, 'expiresAt' => Types::DATETIMETZ_IMMUTABLE];
            $held = $connection->fetchOne('SELECT id FROM shared_idempotency_key WHERE user_id = ? AND idempotency_key = ?', [$parameters['userId'], $parameters['key']]);
            $claimed = false;
            if (false === $held) {
                $claimed = $connection->fetchOne(
                    <<<'SQL'
                        INSERT INTO shared_idempotency_key
                            (id, user_id, idempotency_key, request_fingerprint, status, created_at, expires_at)
                        VALUES (:id, :userId, :key, :fingerprint, :status, :now, :expiresAt)
                        ON CONFLICT (user_id, idempotency_key) DO NOTHING
                        RETURNING id
                        SQL,
                    $parameters,
                    $types,
                );
            }

            if (false === $claimed) {
                $claimed = $connection->fetchOne(
                    <<<'SQL'
                        UPDATE shared_idempotency_key
                        SET id = :id, request_fingerprint = :fingerprint, status = :status,
                            response_status = NULL, response_headers = NULL, response_body = NULL,
                            created_at = :now, expires_at = :expiresAt
                        WHERE id IN (
                            SELECT id FROM shared_idempotency_key
                            WHERE user_id = :userId AND idempotency_key = :key AND expires_at <= :now
                            FOR UPDATE SKIP LOCKED
                        )
                        RETURNING id
                        SQL,
                    $parameters,
                    $types,
                );
            }

            return \is_string($claimed) ? Uuid::fromString($claimed) : null;
        });
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
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE shared_idempotency_key
                SET status = :status, response_status = :responseStatus,
                    response_headers = :headers, response_body = :body
                WHERE id = :id AND status = :inFlight
                SQL,
            [
                'status' => RecordStatus::Completed->value,
                'responseStatus' => $status,
                'headers' => $headers,
                'body' => $body,
                'id' => $id->toRfc4122(),
                'inFlight' => RecordStatus::InFlight->value,
            ],
            ['headers' => Types::JSON],
        );
        if (1 !== $affected) {
            throw new RuntimeException('La réservation idempotente a été perdue avant son reçu.');
        }
    }

    /**
     * Une tentative qui a échoué n'est pas un résultat à rejouer : garder la clé
     * condamnerait le joueur à la même erreur pendant vingt-quatre heures.
     */
    public function release(Uuid $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_idempotency_key WHERE id = :id AND status = :inFlight',
            ['id' => $id->toRfc4122(), 'inFlight' => RecordStatus::InFlight->value],
        );
    }
}
