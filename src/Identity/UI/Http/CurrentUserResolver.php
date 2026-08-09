<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Injecte le `User` du domaine dans un contrôleur qui le type. Sans ça, chaque
 * contrôleur authentifié referait le même aller-retour SecurityUser → repository.
 *
 * Le compte est relu à chaque requête : un jeton reste valide quinze minutes, et
 * pendant ce temps le profil a pu changer.
 */
final readonly class CurrentUserResolver implements ValueResolverInterface
{
    public function __construct(
        private Security $security,
        private UserRepository $users,
    ) {
    }

    /**
     * @return iterable<User>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (User::class !== $argument->getType()) {
            return [];
        }

        $securityUser = $this->security->getUser();

        if (!$securityUser instanceof SecurityUser) {
            throw new AccessDeniedException();
        }

        $user = $this->users->ofId(Uuid::fromString($securityUser->getUserIdentifier()));

        if (null === $user) {
            // Compte supprimé pendant la durée de vie du jeton.
            throw new AccessDeniedException();
        }

        return [$user];
    }
}
