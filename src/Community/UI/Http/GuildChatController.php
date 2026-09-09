<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\SendGuildMessage;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use App\Community\UI\Http\Request\ChatHistoryRequest;
use App\Community\UI\Http\Request\SendChatMessageRequest;
use App\Community\UI\Http\Response\GuildMessageResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final readonly class GuildChatController
{
    public function __construct(private SendGuildMessage $send, private GuildMessageRepository $messages, private int $chatTextMaxLength)
    {
    }

    #[Route('/api/guilds/{id}/chat/messages', name: 'community_chat_send', methods: ['POST'])]
    #[OA\Tag(name: 'Chat de guilde')]
    #[OA\Response(response: 201, description: 'Message enregistré ou rejoué.', content: new OA\JsonContent(ref: '#/components/schemas/GuildMessage'))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, description: 'Identifiant client réutilisé pour un autre contenu.')]
    #[OA\Response(response: 422, description: 'Contenu invalide.')]
    public function send(#[VisibleGuild] Guild $guild, #[CurrentUser] UserInterface $user, #[MapRequestPayload] SendChatMessageRequest $input): JsonResponse
    {
        $text = trim($input->text);
        if ('' === $text || mb_strlen($text) > $this->chatTextMaxLength) {
            throw new UnprocessableEntityHttpException('Le texte est vide ou dépasse la limite autorisée.');
        }

        return new JsonResponse(GuildMessageResource::from($this->send->send($guild->id(), Uuid::fromString($user->getUserIdentifier()), Uuid::fromString($input->clientId), $text)), 201, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/guilds/{id}/chat/messages', name: 'community_chat_history', methods: ['GET'])]
    #[OA\Tag(name: 'Chat de guilde')]
    #[OA\Response(response: 200, description: 'Historique décroissant ; avec after, rattrapage croissant.', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(ref: '#/components/schemas/GuildMessage')),
        new OA\Property(property: 'nextCursor', type: 'string', nullable: true),
    ]))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function history(#[VisibleGuild] Guild $guild, #[MapQueryString(validationFailedStatusCode: 422)] ChatHistoryRequest $query = new ChatHistoryRequest()): JsonResponse
    {
        if (null !== $query->before && null !== $query->after) {
            throw new UnprocessableEntityHttpException('before et after sont exclusifs.');
        }
        $messages = $this->messages->page($guild, null === $query->before ? null : (int) $query->before, null === $query->after ? null : (int) $query->after, $query->limit + 1);
        $more = \count($messages) > $query->limit;
        if ($more) {
            array_pop($messages);
        }
        $last = $messages[array_key_last($messages)] ?? null;

        return new JsonResponse(['messages' => array_map(GuildMessageResource::from(...), $messages), 'nextCursor' => $more && null !== $last ? (string) $last->position() : null], headers: ['Cache-Control' => 'private, no-store']);
    }
}
