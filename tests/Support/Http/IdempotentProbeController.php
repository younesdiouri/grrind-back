<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Shared\UI\Http\Idempotent;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Sonde de test pour le mécanisme d'idempotence, routée dans le seul environnement
 * `test`. Elle existe parce que le mécanisme est transverse et n'a pas encore
 * d'écriture métier à protéger — la première sera la complétion de séance.
 *
 * Son `runId` est tiré à chaque exécution : deux réponses portant le même prouvent
 * que le contrôleur n'a tourné qu'une fois. C'est la « seule écriture » du ticket,
 * sans table de test à maintenir.
 */
#[Route('/api/_probe/idempotent', name: 'test_idempotent_probe', methods: ['POST'])]
#[Idempotent]
final class IdempotentProbeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        // De quoi observer la libération de la clé quand le traitement casse…
        if (true === ($payload['fail'] ?? false)) {
            throw new RuntimeException('Panne simulée.');
        }

        // …et sa conservation quand c'est une règle métier qui refuse.
        if (true === ($payload['refuse'] ?? false)) {
            throw new ConflictHttpException('Refus simulé.');
        }

        return new JsonResponse(
            ['runId' => Uuid::v7()->toRfc4122()],
            Response::HTTP_CREATED,
            ['Location' => '/api/_probe/idempotent'],
        );
    }
}
