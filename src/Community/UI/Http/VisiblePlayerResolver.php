<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Domain\Exception\PlayerNotFound;
use App\Community\Infrastructure\Security\PlayerVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Résout l'identifiant d'un joueur qu'on a le droit de regarder, ou lève.
 *
 * **Les trois refus ne font qu'une réponse : 404.** Identifiant malformé, joueur inconnu,
 * joueur qui n'est pas un co-équipier — la même, au champ près. Ici la règle vaut encore
 * plus qu'ailleurs : un 403 confirmerait qu'un compte porte cet UUID, et les UUID v7
 * encodent leur instant de création. L'API deviendrait un moyen d'énumérer les comptes
 * ouverts un jour donné.
 *
 * **Le voter ne peut pas rendre 404 lui-même** — un voter répond oui ou non, et Symfony
 * traduit le non en 403. C'est pour ça que la décision est ici et pas dans un
 * `#[IsGranted]` : l'attribut donnerait le bon refus avec le mauvais code.
 *
 * @see https://symfony.com/doc/current/controller/value_resolver.html
 */
#[AsTargetedValueResolver]
final readonly class VisiblePlayerResolver implements ValueResolverInterface
{
    public function __construct(private AuthorizationCheckerInterface $authorization)
    {
    }

    /**
     * @return iterable<Uuid>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Uuid::class !== $argument->getType()) {
            return [];
        }

        $id = $request->attributes->get('id');

        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new PlayerNotFound();
        }

        $playerId = Uuid::fromString($id);

        if (!$this->authorization->isGranted(PlayerVoter::VIEW, $playerId)) {
            throw new PlayerNotFound();
        }

        return [$playerId];
    }
}
