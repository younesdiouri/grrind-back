<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\ChatChangedHandler;
use App\Community\Domain\Guild;
use DateTimeInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Le jeton ne transporte aucune identité ni permission de publication. */
final readonly class ChatSubscriptionController
{
    public function __construct(private HubInterface $hub, private ClockInterface $clock)
    {
    }

    #[Route('/api/guilds/{id}/chat/subscription', name: 'community_chat_subscription', methods: ['POST'])]
    #[RateLimit('guild_chat_subscription', key: new Expression('args["user"].getUserIdentifier()'))]
    #[OA\Tag(name: 'Chat de guilde')]
    #[OA\Response(response: 200, description: 'Jeton de lecture du signal, valable 5 minutes. Aucun contenu privé dans Mercure.', content: new OA\JsonContent(required: ['url', 'topic', 'token', 'expiresAt'], properties: [
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
        new OA\Property(property: 'topic', type: 'string', format: 'uri'),
        new OA\Property(property: 'token', type: 'string'),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
    ]))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 429, description: 'Trop de renouvellements de jeton.')]
    public function __invoke(#[VisibleGuild] Guild $guild, #[CurrentUser] UserInterface $user): JsonResponse
    {
        $topic = ChatChangedHandler::topic($guild->id()->toRfc4122());
        $now = $this->clock->now();
        $expires = $now->setTimestamp($now->getTimestamp() + 300);
        $factory = $this->hub->getFactory();
        \assert(null !== $factory);

        return new JsonResponse(['url' => $this->hub->getPublicUrl(), 'topic' => $topic, 'token' => $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], [$topic])], ['exp' => $expires]), 'expiresAt' => $expires->format(DateTimeInterface::ATOM)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
