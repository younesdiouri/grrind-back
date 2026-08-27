<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Application\DailyActivityEntry;
use App\Training\Application\UpsertDailyActivity;
use App\Training\Application\UpsertDailyActivityHandler;
use App\Training\UI\Http\Request\DailyActivityEntryRequest;
use App\Training\UI\Http\Request\DailyActivityUpsertRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * L'énergie active quotidienne — la moitié « sédentarité » de Vitality (#165), à côté de la
 * moitié « variété des sports » que le ledger porte déjà.
 *
 * **Une route à part de `/api/workouts/import`, et c'est tranché par le ticket.** Une
 * journée sédentaire doit pouvoir se remonter sans aucun workout à envoyer — l'inverse
 * forcerait le client à inventer une séance vide pour faire passer une seule donnée.
 * L'idempotence n'y est pas non plus la même : ici c'est l'`UPSERT (user, jour)` qui la
 * porte, jamais un `Idempotency-Key` — rejouer la même journée avec la même valeur ne fait
 * que la réécrire à l'identique, il n'y a pas de crédit à protéger d'un double comptage.
 *
 * **`PUT`, pas `POST`.** La requête ne crée rien qu'on puisse nommer par un identifiant
 * neuf : elle pose l'état de chaque `(user, day)` du lot, ce que le client peut rejouer sans
 * jamais changer le résultat au-delà de sa dernière valeur envoyée — la définition même
 * d'une opération idempotente au sens HTTP, ce qu'un `POST` ne promet pas.
 *
 * `204`, sans corps : contrairement à l'import, aucune mise en scène n'est due ici. La
 * Vitality bonifiée que ce lot influence se lit sur `GET /api/progression`, jamais dans
 * cette réponse — voir le docblock de `ProgressionResource`.
 */
final readonly class DailyActivityController
{
    public function __construct(private UpsertDailyActivityHandler $upsert)
    {
    }

    #[Route('/api/daily-activity', name: 'training_daily_activity_upsert', methods: ['PUT'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DailyActivityUpsertRequest'))]
    #[OA\Response(
        response: 204,
        description: 'Chaque journée du lot est à jour. Une journée déjà connue a été révisée, jamais dupliquée.',
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        DailyActivityUpsertRequest $request,
    ): Response {
        ($this->upsert)(new UpsertDailyActivity(
            Uuid::fromString($user->getUserIdentifier()),
            array_map(self::entry(...), $request->days),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Le DTO de requête décrit ce qu'un client HTTP a le droit d'envoyer, la commande ce que
     * le métier consomme — même idiome qu'`ImportWorkoutsController::candidate()`. Les
     * valeurs non nulles sont garanties par la validation, qui a déjà rendu un 422 sinon.
     */
    private static function entry(DailyActivityEntryRequest $request): DailyActivityEntry
    {
        \assert(null !== $request->day && null !== $request->activeEnergyKcal && null !== $request->source);

        return new DailyActivityEntry($request->day, $request->activeEnergyKcal, $request->source);
    }
}
