<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Security;

use App\Combat\Domain\Battle;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Qui a le droit de revoir un combat. **Celui qui l'a mené, et personne d'autre en v1** —
 * pas d'observateur, pas de guilde spectatrice.
 *
 * Même geste que {@see \App\Community\Infrastructure\Security\GuildVoter} : le sujet est
 * l'entité elle-même, pas un UUID nu — {@see \App\Combat\UI\Http\VisibleBattleResolver} l'a
 * déjà chargée, et c'est ce même exemplaire que le contrôleur sert. Le choix inverse,
 * {@see \App\Community\Infrastructure\Security\PlayerVoter} qui ne vote que sur un UUID, ne
 * s'applique pas ici : `PlayerVoter` évite une lecture en base pour le cas le plus fréquent
 * — se regarder soi-même — quand `Battle` n'a rien d'équivalent à économiser : le
 * contrôleur a de toute façon besoin de la ligne entière pour rendre la timeline.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 *
 * @extends Voter<string, Battle>
 */
final class BattleVoter extends Voter
{
    /** Revoir un combat déjà joué. */
    public const string VIEW = 'BATTLE_VIEW';

    /** Déclaré pour que le voter soit cachable — voir {@see CacheableVoterInterface}. */
    public function supportsAttribute(string $attribute): bool
    {
        return self::VIEW === $attribute;
    }

    /** `is_a()` et non une comparaison stricte : Doctrine sert des proxies, pas des `Battle`. */
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Battle::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && $subject instanceof Battle;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Le sujet est forcément un `Battle` : `supports()` l'a déjà exigé.
        $viewer = self::playerOf($token);

        if (null === $viewer) {
            $vote?->addReason('Aucun joueur authentifié.');

            return false;
        }

        if ($subject->playerId()->equals($viewer)) {
            return true;
        }

        $vote?->addReason('Ce combat n\'appartient pas à ce joueur.');

        return false;
    }

    private static function playerOf(TokenInterface $token): ?Uuid
    {
        $identifier = $token->getUserIdentifier();

        return Uuid::isValid($identifier) ? Uuid::fromString($identifier) : null;
    }
}
