<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\ChatImageStorage;
use App\Community\Application\SendGuildMessage;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use App\Community\Infrastructure\Storage\ChatImageProcessor;
use App\Community\UI\Http\Request\ChatHistoryRequest;
use App\Community\UI\Http\Request\SendChatMessageRequest;
use App\Community\UI\Http\Response\GuildMessageResource;
use LogicException;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class GuildChatController
{
    public function __construct(
        private SendGuildMessage $send,
        private GuildMessageRepository $messages,
        private int $chatTextMaxLength,
        private ChatImageProcessor $processor,
        private ChatImageStorage $images,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/guilds/{id}/chat/messages', name: 'community_chat_send', methods: ['POST'])]
    #[RateLimit('guild_chat_send', key: new Expression('args["user"].getUserIdentifier()'))]
    #[OA\Tag(name: 'Chat de guilde')]
    #[OA\RequestBody(required: true, content: [
        new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(required: ['clientId', 'text'], properties: [
            new OA\Property(property: 'clientId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'text', type: 'string', description: 'Texte brut, 4 000 caractères par défaut.'),
        ])),
        new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['clientId'], properties: [
            new OA\Property(property: 'clientId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'text', type: 'string'),
            new OA\Property(property: 'image', type: 'string', format: 'binary', description: 'Un JPEG, PNG ou WebP réel ; 5 Mio / 20 mégapixels par défaut. Texte ou image requis.'),
        ])),
    ])]
    #[OA\Response(response: 201, description: 'Message enregistré ou rejoué.', content: new OA\JsonContent(ref: '#/components/schemas/GuildMessage'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, description: 'Identifiant client réutilisé pour un autre contenu.')]
    #[OA\Response(response: 422, description: 'Contenu invalide.')]
    #[OA\Response(response: 429, description: '30 tentatives par minute et par joueur, rejeux compris. Retry-After indique le délai.')]
    public function send(
        #[VisibleGuild]
        Guild $guild,
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload(acceptFormat: ['json', 'form'], serializationContext: ['allow_extra_attributes' => false])]
        SendChatMessageRequest $input,
        Request $request,
    ): JsonResponse {
        // Seul le fichier multipart de PHP est admissible, jamais un objet décrit en JSON.
        if ($input->image !== $request->files->get('image')) {
            throw new UnprocessableEntityHttpException('Une image exige un fichier multipart.');
        }
        $text = trim($input->text);
        if (('' === $text && null === $input->image) || str_contains($text, "\0")) {
            throw new UnprocessableEntityHttpException('Le texte est vide ou dépasse la limite autorisée.');
        }
        if ($this->chatTextMaxLength < 1) {
            throw new LogicException('La limite de texte doit être positive.');
        }
        $violations = $this->validator->validate($text, new Length(max: $this->chatTextMaxLength));
        if (\count($violations) > 0) {
            throw new ValidationFailedException($input, $violations);
        }

        $image = null === $input->image ? null : $this->processor->prepare($input->image);

        return new JsonResponse(GuildMessageResource::from($this->send->send($guild->id(), Uuid::fromString($user->getUserIdentifier()), Uuid::fromString($input->clientId), $text, $image)), 201, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/guilds/{id}/chat/messages/{messageId}/image', name: 'community_chat_image', methods: ['GET'])]
    #[OA\Tag(name: 'Chat de guilde')]
    #[OA\Response(response: 200, description: 'Image privée réencodée, sans cache.', content: new OA\MediaType(mediaType: 'image/webp', schema: new OA\Schema(type: 'string', format: 'binary')))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function image(#[VisibleGuild] Guild $guild, string $messageId): Response
    {
        $message = Uuid::isValid($messageId) ? $this->messages->findOneBy(['id' => Uuid::fromString($messageId), 'guild' => $guild]) : null;
        if (null === $message || null === $key = $message->imageKey()) {
            throw new NotFoundHttpException();
        }

        return new Response($this->images->read($key), headers: ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
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
