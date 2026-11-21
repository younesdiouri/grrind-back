<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\GuildRecruitment;
use App\Community\Application\PublicGuild;
use App\Community\Domain\Exception\ApplicationNotFound;
use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildApplication;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildApplicationRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Security\GuildVoter;
use App\Community\UI\Http\Request\RecruitmentPageQuery;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[OA\Tag(name: 'Guildes')]
final readonly class GuildRecruitmentController
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildApplicationRepository $applications,
        private GuildRecruitment $recruitment,
        private GuildRules $rules,
    ) {
    }

    #[Route('/api/guilds/directory', name: 'guild_recruitment_directory', methods: ['GET'], priority: 10)]
    #[OA\Response(response: 200, description: 'Annuaire des guildes ouvertes.', content: new OA\JsonContent(ref: '#/components/schemas/GuildDirectoryPage'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function directory(#[MapQueryString(validationFailedStatusCode: 422)] RecruitmentPageQuery $query = new RecruitmentPageQuery()): JsonResponse
    {
        $guilds = $this->guilds->directory($query->cursor, $query->limit + 1);

        return new JsonResponse($query->page(array_map(fn (PublicGuild $guild): array => $guild->toArray($this->rules->maximumMembers()), $guilds)));
    }

    #[Route('/api/guilds/{id}/preview', name: 'guild_recruitment_preview', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Aperçu de guilde visible au lecteur.', content: new OA\JsonContent(ref: '#/components/schemas/PublicGuild'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function preview(string $id, #[CurrentUser] UserInterface $user): JsonResponse
    {
        $guild = $this->guilds->preview(self::guildId($id), Uuid::fromString($user->getUserIdentifier())) ?? throw new GuildNotFound();

        return new JsonResponse($guild->toArray($this->rules->maximumMembers()));
    }

    #[Route('/api/guilds/{id}/applications', name: 'guild_recruitment_submit', methods: ['POST'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 200, description: 'Candidature en attente. Un rejeu avec la même clé rend le reçu initial.', content: new OA\JsonContent(ref: '#/components/schemas/GuildApplication'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 400, description: 'Clé d’idempotence absente ou invalide.')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function submit(string $id, #[CurrentUser] UserInterface $user): JsonResponse
    {
        $application = $this->recruitment->submit(self::guildId($id), Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse($application->toArray());
    }

    #[Route('/api/guild-applications/mine', name: 'guild_recruitment_mine', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Historique des candidatures du joueur connecté.', content: new OA\JsonContent(ref: '#/components/schemas/GuildApplicationPage'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function mine(#[CurrentUser] UserInterface $user, #[MapQueryString(validationFailedStatusCode: 422)] RecruitmentPageQuery $query = new RecruitmentPageQuery()): JsonResponse
    {
        return $this->applicationPage(Uuid::fromString($user->getUserIdentifier()), false, $query);
    }

    #[Route('/api/guilds/{id}/applications', name: 'guild_recruitment_inbox', methods: ['GET'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Response(response: 200, description: 'Candidatures en attente réservées au meneur.', content: new OA\JsonContent(ref: '#/components/schemas/GuildApplicationPage'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function inbox(#[VisibleGuild] Guild $guild, #[MapQueryString(validationFailedStatusCode: 422)] RecruitmentPageQuery $query = new RecruitmentPageQuery()): JsonResponse
    {
        return $this->applicationPage($guild->id(), true, $query);
    }

    #[Route('/api/guilds/{id}/applications/{applicationId}/accept', name: 'guild_recruitment_accept', methods: ['POST'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Response(response: 200, description: 'Admet le candidat et retire ses autres candidatures.', content: new OA\JsonContent(ref: '#/components/schemas/GuildApplication'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function accept(#[VisibleGuild] Guild $guild, string $applicationId, #[CurrentUser] UserInterface $user): JsonResponse
    {
        return $this->decision($guild, $applicationId, $user, true);
    }

    #[Route('/api/guilds/{id}/applications/{applicationId}/reject', name: 'guild_recruitment_reject', methods: ['POST'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Response(response: 200, description: 'Refuse la candidature.', content: new OA\JsonContent(ref: '#/components/schemas/GuildApplication'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function reject(#[VisibleGuild] Guild $guild, string $applicationId, #[CurrentUser] UserInterface $user): JsonResponse
    {
        return $this->decision($guild, $applicationId, $user, false);
    }

    private function decision(Guild $guild, string $applicationId, UserInterface $user, bool $accept): JsonResponse
    {
        if (!Uuid::isValid($applicationId)) {
            throw new ApplicationNotFound();
        }
        $application = $this->recruitment->decide($guild->id(), Uuid::fromString($user->getUserIdentifier()), Uuid::fromString($applicationId), $accept);

        return new JsonResponse($application->toArray());
    }

    private function applicationPage(Uuid $owner, bool $inbox, RecruitmentPageQuery $query): JsonResponse
    {
        $rows = $this->applications->page($owner, $inbox, $query->cursor, $query->limit + 1);

        return new JsonResponse($query->page(array_map(static fn (GuildApplication $application): array => $application->toArray(), $rows)));
    }

    private static function guildId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw new GuildNotFound();
        }

        return Uuid::fromString($id);
    }
}
