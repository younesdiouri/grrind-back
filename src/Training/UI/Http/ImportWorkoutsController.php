<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Shared\Application\GameRulesets;
use App\Shared\UI\Http\Idempotent;
use App\Training\Application\ImportedWorkout;
use App\Training\Application\ImportWorkouts;
use App\Training\Application\ImportWorkoutsHandler;
use App\Training\UI\Http\Request\ImportedWorkoutRequest;
use App\Training\UI\Http\Request\ImportWorkoutsRequest;
use App\Training\UI\Http\Response\SyncSummaryResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La nouvelle porte d'entrée du produit : le client envoie ce que la montre a enregistré
 * depuis sa dernière synchronisation, le serveur le crédite.
 *
 * ————— C'est la première fois que le serveur accepte des dates du client ————————————
 *
 * Ça mérite d'être écrit noir sur blanc, parce que ça a l'air de contredire un invariant.
 * Ce ne sont pas les dates *du client* : ce sont celles **du fournisseur**, et c'est le seul
 * endroit d'où elles peuvent venir — une séance a eu lieu avant que Grrind en entende
 * parler, et il n'existe aucune horloge serveur pour un fait passé.
 *
 * L'invariant ne disparaît pas, il se déplace : le serveur ne *possède* plus l'horloge, il
 * l'**arbitre**. Concrètement, ici et maintenant : la durée n'est pas un champ acceptable,
 * elle se recalcule des deux bornes ; `createdAt` reste l'heure du serveur. Et surtout,
 * l'arbitrage complet — fenêtre d'antériorité, chevauchement, plancher, plafond — arrive au
 * #91. Tant qu'il n'est pas là, ces dates sont crues, et c'est le trou connu de ce ticket.
 *
 * ————— Deux protections, et il en faut deux ——————————————————————————————————————————
 *
 * L'unicité `(user, source, externalId)` est la vraie : elle survit à une app réinstallée,
 * à un curseur perdu, à deux appareils du même compte branchés sur le même HealthKit.
 *
 * `Idempotency-Key` ne fait pas doublon avec elle, elle couvre ce qu'elle ne couvre pas : le
 * rejeu d'une requête dont la **réponse** s'est perdue. Sans elle, le client rejoue, tous
 * les workouts sont écartés comme doublons, et il reçoit un import vide au lieu de *sa*
 * mise en scène — l'XP serait juste, l'animation perdue.
 */
final readonly class ImportWorkoutsController
{
    public function __construct(
        private ImportWorkoutsHandler $import,
        /** Le snapshot lu pendant l'import est aussi celui annoncé à son client. */
        private string|GameRulesets $rulesetVersion,
    ) {
    }

    #[Route('/api/workouts/import', name: 'training_workout_import', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkoutImportRequest'))]
    #[OA\Response(
        response: 200,
        description: 'La synchronisation, prête à être jouée. Un import où tout est écarté reste un succès.',
        content: new OA\JsonContent(ref: '#/components/schemas/SyncSummary'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        ImportWorkoutsRequest $request,
    ): JsonResponse {
        $import = ($this->import)(new ImportWorkouts(
            Uuid::fromString($user->getUserIdentifier()),
            array_map(self::candidate(...), $request->workouts),
        ));

        // 200 et non 201 : un lot n'est pas une ressource. Il peut n'en créer aucune — le
        // cas le plus fréquent, un client qui resynchronise sans rien de neuf — et quand il
        // en crée trois, il n'y a pas d'URL unique à mettre dans `Location`.
        return new JsonResponse(SyncSummaryResource::from($import, $this->version())->toArray());
    }

    private function version(): string
    {
        return \is_string($this->rulesetVersion) ? $this->rulesetVersion : $this->rulesetVersion->version();
    }

    /**
     * Le DTO de requête décrit ce qu'un client HTTP a le droit d'envoyer, la commande ce
     * que le métier consomme. Les valeurs non nulles sont garanties par la validation, qui
     * a déjà rendu un 422 sinon.
     */
    private static function candidate(ImportedWorkoutRequest $request): ImportedWorkout
    {
        \assert(null !== $request->source && null !== $request->startedAt && null !== $request->endedAt);

        return new ImportedWorkout(
            $request->externalId,
            $request->source,
            $request->activityType,
            $request->startedAt,
            $request->endedAt,
            $request->distanceMeters,
            $request->calories,
            $request->elevationGainMeters,
            $request->averageHeartRate,
        );
    }
}
