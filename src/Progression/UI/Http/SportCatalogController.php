<?php

declare(strict_types=1);

namespace App\Progression\UI\Http;

use App\Progression\Domain\XpCalculator;
use App\Progression\UI\Http\Response\SportCatalogResource;
use App\Shared\Application\GameRulesets;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le catalogue dépend du moteur d'XP, d'où sa place dans Progression plutôt que Training.
 * Revalidation à chaque ouverture du guide : aucune expiration ne masque une publication.
 * L'ETag porte le JSON traduit : les libellés sont exclus de la version de gameplay.
 * https://symfony.com/doc/current/http_cache.html#validation-caching.
 */
final readonly class SportCatalogController
{
    public function __construct(
        private GameRulesets $rulesets,
        private XpCalculator $calculator,
        private DisciplineTranslator $translator,
    ) {
    }

    #[Route('/api/sports', name: 'progression_sport_catalog', methods: ['GET'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Parameter(name: 'If-None-Match', in: 'header', description: 'ETag du dernier catalogue dans cette langue.', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Catalogue actif et barème de référence, filtrable localement. Revalider à chaque ouverture du guide.', content: new OA\JsonContent(ref: '#/components/schemas/SportCatalog'))]
    #[OA\Response(response: 304, description: 'Catalogue inchangé pour cet ETag et cette langue.')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(Request $request): JsonResponse
    {
        $catalog = SportCatalogResource::from($this->rulesets, $this->calculator, $this->translator, $request->getLocale());
        $response = new JsonResponse($catalog->toArray(), headers: ['Cache-Control' => 'private, no-cache']);
        $response->setVary('Accept-Language');
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
