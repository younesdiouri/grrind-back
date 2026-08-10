<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * De quoi écrire des tests de séances sans attendre le temps réel.
 *
 * Le serveur possède l'horloge et **aucune route ne permet d'antidater** — c'est
 * l'invariant du projet, pas un oubli. Une suite de tests a pourtant besoin de séances
 * qui durent une demi-heure et de cooldowns écoulés. Elle les obtient en reculant la
 * séance *déjà écrite* directement en base : le serveur continue de dater ce qu'il
 * date, on ne fait que déplacer le passé. C'est plus honnête qu'une horloge simulée,
 * qui prouverait que le code marche avec une fausse heure et non avec la vraie.
 *
 * @phpstan-require-extends ApiTestCase
 */
trait TrainingSessions
{
    protected function startSession(Account $account, string $discipline = 'RUNNING'): string
    {
        $response = $this->post('/api/training/sessions', ['discipline' => $discipline], $account->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $id = self::decode($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    protected function completeSession(Account $account, string $sessionId): Response
    {
        return $this->closeSession($account, $sessionId, 'complete');
    }

    protected function abandonSession(Account $account, string $sessionId): Response
    {
        return $this->closeSession($account, $sessionId, 'abandon');
    }

    /**
     * Une clé d'idempotence neuve à chaque appel : l'empreinte porte sur le chemin,
     * donc recycler la même clé d'une séance à l'autre serait un abus de clé — et
     * c'est un 409, pas un rejeu.
     */
    protected function closeSession(Account $account, string $sessionId, string $action): Response
    {
        return $this->post(
            \sprintf('/api/training/sessions/%s/%s', $sessionId, $action),
            [],
            $account->headers + ['Idempotency-Key' => Uuid::v4()->toRfc4122()],
        );
    }

    /**
     * Recule la séance entière dans le passé, durée inchangée. Sur une séance en cours,
     * ça allonge d'autant le temps écoulé ; sur une séance close, ça éloigne sa fin et
     * purge donc le cooldown.
     */
    protected function ageSession(string $sessionId, int $seconds): void
    {
        $this->connection()->executeStatement(
            'UPDATE training_session
                SET started_at = started_at - (:seconds * INTERVAL \'1 second\'),
                    ended_at = ended_at - (:seconds * INTERVAL \'1 second\')
              WHERE id = :id',
            ['seconds' => $seconds, 'id' => $sessionId],
        );
    }

    /**
     * Une séance close et rangée dans le passé, cooldown compris : la brique de tout
     * test d'historique. Elle passe par les vraies routes — c'est le stockage qu'on
     * recule, pas le comportement qu'on contourne.
     */
    protected function pastSession(Account $account, string $discipline = 'RUNNING', int $durationSeconds = 1800): string
    {
        $id = $this->startSession($account, $discipline);
        $this->ageSession($id, $durationSeconds);

        $response = $this->completeSession($account, $id);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->ageSession($id, 3600);

        return $id;
    }

    protected function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    protected function statusOf(string $sessionId): string
    {
        $status = $this->connection()->fetchOne('SELECT status FROM training_session WHERE id = :id', ['id' => $sessionId]);
        self::assertIsString($status);

        return $status;
    }
}
