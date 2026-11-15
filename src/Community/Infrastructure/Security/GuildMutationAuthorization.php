<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Security;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le voter HTTP précède le verrou : une succession peut le périmer. Les cinq mutations
 * rejouent les mêmes votes sous verrou, dans leur transaction, avec l'acteur de la commande.
 * GuildVoter ne consomme que son UUID ; aucun compte Identity ne franchit la frontière.
 *
 * @see https://symfony.com/doc/current/security.html#setting-individual-user-permissions
 */
final readonly class GuildMutationAuthorization
{
    public function __construct(
        private GuildRepository $guilds,
        private UserAuthorizationCheckerInterface $authorization,
    ) {
    }

    public function lock(Uuid $guildId, Uuid $actorId, string $permission): Guild
    {
        $guild = $this->guilds->lockForUpdate($guildId);
        $actor = new InMemoryUser($actorId->toRfc4122(), null);

        if (null === $guild || !$this->authorization->isGrantedForUser($actor, GuildVoter::VIEW, $guild)) {
            throw new GuildNotFound();
        }
        if (!$this->authorization->isGrantedForUser($actor, $permission, $guild)) {
            throw new AccessDeniedException();
        }

        return $guild;
    }
}
