<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Application\TogetherTracker;
use App\Training\Domain\Together\LocationEvidence;
use App\Training\UI\Http\Request\LocationEvidenceRequest;
use App\Training\UI\Http\Request\LocationPointRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** MapRequestPayload et Validator : https://symfony.com/doc/current/controller.html#mapping-request-payload */
final readonly class LocationEvidenceController
{
    public function __construct(private TogetherTracker $tracker)
    {
    }

    #[Route('/api/workouts/location-evidence', name: 'training_location_evidence', methods: ['POST'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LocationEvidenceRequest'))]
    #[OA\Response(response: 200, description: 'Lot enregistré ou rejoué ; workout nul avant son import.', content: new OA\JsonContent(ref: '#/components/schemas/LocationEvidenceResult'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 410, description: 'Fenêtre de preuve expirée.', content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails'))]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(#[CurrentUser] UserInterface $user, #[MapRequestPayload] LocationEvidenceRequest $request): JsonResponse
    {
        \assert(null !== $request->batchId && null !== $request->source && null !== $request->startedAt && null !== $request->endedAt);
        $points = array_map(static function (LocationPointRequest $point): array {
            \assert(null !== $point->timestamp && null !== $point->latitude && null !== $point->longitude && null !== $point->horizontalAccuracy);

            return ['at' => $point->timestamp->getTimestamp(), 'latitude' => $point->latitude, 'longitude' => $point->longitude, 'accuracy' => $point->horizontalAccuracy];
        }, $request->points);
        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $digest = hash('sha256', json_encode([$request->source->value, $request->externalId, $request->origin, $request->startedAt->getTimestamp(), $request->endedAt->getTimestamp(), $points], \JSON_THROW_ON_ERROR));
        $workout = $this->tracker->store(new LocationEvidence(Uuid::fromString($user->getUserIdentifier()), $request->batchId, $request->source->value, $request->externalId, $request->origin, $request->startedAt, $request->endedAt, $points, $digest));

        return new JsonResponse(['batchId' => $request->batchId->toRfc4122(), 'workoutId' => $workout?->id()->toRfc4122(), 'together' => $workout?->together()]);
    }
}
