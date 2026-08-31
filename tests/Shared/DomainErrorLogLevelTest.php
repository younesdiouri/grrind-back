<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ApiTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un refus métier se journalise en `warning`, jamais en `critical` (#247).
 *
 * **Ce qu'aucun test d'API ne peut couvrir.** Le statut HTTP d'un `DomainError` est déjà vérifié
 * là où il compte — 401 pour un code refusé, 404 pour un profil invisible. Mais le *niveau* de
 * journalisation ne ressort d'aucune réponse : `ErrorListener` écrit à la priorité 0, avant que
 * `ProblemDetailsListener` (-64) ne construise le problème, et rien de ce qu'il écrit ne sort par
 * HTTP. Une régression ici serait muette pour toute la suite — c'est précisément ce qu'elle a
 * été jusqu'au #247.
 *
 * **Le handler se pousse à l'exécution plutôt que de se déclarer dans `monolog.yaml`.** Un
 * handler de capture sous `when@test` n'existerait pas dans le conteneur `dev`, contre lequel
 * PHPStan analyse `tests/` — et le faire exister partout mettrait un `TestHandler` en production.
 * Le canal `request` est un service des deux côtés, donc l'attraper ici ne coûte aucune
 * configuration et ne ment sur aucun environnement.
 */
final class DomainErrorLogLevelTest extends ApiTestCase
{
    public function testABusinessRefusalIsLoggedAsAWarningAndNeverAsCritical(): void
    {
        $records = new TestHandler();

        // Le canal sur lequel `ErrorListener` écrit ses exceptions.
        self::getContainer()->get('monolog.logger.request')->pushHandler($records);

        // Sans ça, le navigateur de test redémarre le noyau entre deux requêtes et jetterait le
        // handler qu'on vient de poser. Une seule requête suit, mais l'écrire vaut mieux que
        // dépendre de l'ordre interne de `KernelBrowser`.
        $this->client->disableReboot();

        // Un code que le fournisseur refuse : `SocialSignInRejected`, donc un `DomainError`,
        // rendu en 401. Le chemin le plus court jusqu'à la branche `CRITICAL` d'origine.
        $response = $this->post('/api/auth/social/google', [
            'code' => 'ce-code-a-expire',
            'redirectUri' => 'app.grrind://auth/google',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        // Deux assertions, et la seconde est celle qui tient. Qu'un `warning` existe dit que
        // l'entrée `framework.exceptions` est lue ; qu'aucun `critical` ne l'accompagne dit
        // qu'elle a bien *remplacé* le niveau par défaut au lieu de s'ajouter à côté — la
        // première seule passerait encore si Symfony écrivait les deux.
        self::assertTrue(
            $records->hasWarningThatContains('SocialSignInRejected'),
            "Le refus aurait dû laisser un `warning` — l'entrée `framework.exceptions` n'est pas lue.",
        );

        self::assertFalse(
            $records->hasRecordThatMatches('/./', Level::Critical),
            "Un refus métier ne doit jamais sortir en `critical` : c'est le défaut du #247.",
        );
    }
}
