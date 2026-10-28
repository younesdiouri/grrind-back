<?php

declare(strict_types=1);

namespace App\Progression\UI\Http;

use App\Progression\Domain\TogetherBonusReceipt;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class TogetherBonusesController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/api/workouts/together-bonuses', name: 'progression_together_bonuses', methods: ['GET'])]
    #[OA\Tag(name: 'Progression')]
    #[OA\Parameter(name: 'after', in: 'query', description: 'Dernier identifiant présenté, UUID v7 ; ordre de crédit croissant.', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50))]
    #[OA\Response(response: 200, description: 'Compléments relisibles du joueur courant ; conserver nextCursor côté appareil après présentation.', content: new OA\JsonContent(ref: '#/components/schemas/TogetherBonusPage'))]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(#[CurrentUser] UserInterface $user, #[MapQueryParameter] ?string $after = null, #[MapQueryParameter] int $limit = 50): JsonResponse
    {
        if ($limit < 1 || $limit > 100 || (null !== $after && !\Symfony\Component\Uid\Uuid::isValid($after))) {
            throw new BadRequestHttpException('Curseur ou limite invalide.');
        }
        $query = $this->em->createQueryBuilder()->select('r')->from(TogetherBonusReceipt::class, 'r')->where('r.userId = :user')->setParameter('user', $user->getUserIdentifier())->orderBy('r.id', 'ASC')->setMaxResults($limit + 1);
        if (null !== $after) {
            $query->andWhere('r.id > :after')->setParameter('after', $after);
        }
        /** @var list<TogetherBonusReceipt> $receipts */
        $receipts = $query->getQuery()->getResult();
        $more = \count($receipts) > $limit;
        $receipts = \array_slice($receipts, 0, $limit);
        $last = [] !== $receipts ? $receipts[array_key_last($receipts)]->id->toRfc4122() : $after;

        return new JsonResponse(['bonuses' => array_map(static fn (TogetherBonusReceipt $r): array => $r->payload, $receipts), 'nextCursor' => $last, 'hasMore' => $more]);
    }
}
