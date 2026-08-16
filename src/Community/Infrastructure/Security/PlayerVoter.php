<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Security;

use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Qui a le droit de regarder le profil de qui. **Soi-même, ou un co-équipier. Rien d'autre
 * en v1.**.
 *
 * **Pourquoi ce voter vit dans `Community` alors qu'il parle de joueurs.** L'autorisation
 * qu'il porte est « sommes-nous de la même guilde », et c'est une question à laquelle seul
 * ce module sait répondre. Le mettre dans `Identity` obligerait celui-ci à connaître les
 * guildes — la flèche exacte que Deptrac existe pour empêcher.
 *
 * Le jour où les classements auront besoin d'élargir la règle (« adversaire de ma ligue
 * cette semaine »), c'est ici que la clause s'ajoutera, à côté de celle-ci. C'est aussi
 * pourquoi la route est `/api/players/{id}` et non `/api/guilds/mine/members/{id}` : la
 * seconde forme aurait évité le débat sur l'invariant, mais enfermerait le profil public
 * dans la guilde alors que le classement en aura besoin aussi.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 *
 * @extends Voter<string, Uuid>
 */
final class PlayerVoter extends Voter
{
    /** Voir le profil public d'un joueur. */
    public const string VIEW = 'PLAYER_VIEW';

    public function __construct(private readonly GuildMembershipRepository $memberships)
    {
    }

    /** Déclaré pour que le voter soit cachable — voir {@see CacheableVoterInterface}. */
    public function supportsAttribute(string $attribute): bool
    {
        return self::VIEW === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Uuid::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && $subject instanceof Uuid;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $viewer = self::playerOf($token);

        if (null === $viewer) {
            $vote?->addReason('Aucun joueur authentifié.');

            return false;
        }

        // Soi-même d'abord, et sans toucher la base : c'est le cas le plus fréquent, et
        // c'est aussi le seul qui doive marcher pour un joueur sans guilde.
        if ($viewer->equals($subject)) {
            return true;
        }

        if ($this->memberships->shareAGuild($viewer, $subject)) {
            return true;
        }

        $vote?->addReason('Ce joueur n\'est pas un co-équipier.');

        return false;
    }

    private static function playerOf(TokenInterface $token): ?Uuid
    {
        $identifier = $token->getUserIdentifier();

        return Uuid::isValid($identifier) ? Uuid::fromString($identifier) : null;
    }
}
