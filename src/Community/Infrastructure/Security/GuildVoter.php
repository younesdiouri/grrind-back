<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Security;

use App\Community\Domain\Guild;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Qui a le droit de quoi sur une guilde. **Aucun contrôleur n'écrit `if ($role === …)`** :
 * la question se pose à `#[IsGranted]`, la réponse est ici, et elle est au même endroit
 * pour les sept routes du module.
 *
 * Le rôle tient en deux valeurs d'enum et un voter — pas de colonne `permissions`, pas de
 * table de droits. C'est la règle n°0 : le composant Security sait déjà faire ça, et une
 * table de droits serait une seconde façon de répondre à une question qui en a déjà une.
 *
 * **Le joueur est lu par `getUserIdentifier()`, pas par l'entité `User`.** Deptrac interdit
 * à `Community` d'importer `Identity`, et cette frontière n'est pas contournée ici : c'est
 * exactement pour ça que l'identifiant de sécurité est l'UUID du compte et non l'adresse
 * (voir `App\Identity\Domain\User::getUserIdentifier()`). Le voter reçoit ce dont il a
 * besoin — un UUID — sans rien apprendre du compte.
 *
 * `GUILD_KICK` n'est pas ici : l'exclusion arrive avec le #118, avec ses règles à elle.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 *
 * @extends Voter<string, Guild>
 */
final class GuildVoter extends Voter
{
    /** Voir la guilde et ses membres. Tout membre, et personne d'autre. */
    public const string VIEW = 'GUILD_VIEW';

    /** Renommer. Fondateur seul. */
    public const string EDIT = 'GUILD_EDIT';

    /** Dissoudre. Fondateur seul — c'est l'acte irréversible du module. */
    public const string DISSOLVE = 'GUILD_DISSOLVE';

    /**
     * Déclaré pour que le voter soit **cachable** : sans lui, Symfony l'interroge pour
     * chaque attribut de chaque décision de toute l'API, y compris `IS_AUTHENTICATED_FULLY`
     * sur des routes qui n'ont jamais vu une guilde.
     *
     * @see CacheableVoterInterface
     */
    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DISSOLVE], true);
    }

    /** `is_a()` et non une comparaison stricte : Doctrine sert des proxies, pas des `Guild`. */
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Guild::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && $subject instanceof Guild;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Le sujet est forcément une `Guild` : `supports()` l'a déjà exigé, et c'est ce
        // que dit `@extends Voter<string, Guild>` à l'analyse statique.
        $playerId = self::playerOf($token);

        if (null === $playerId) {
            $vote?->addReason('Aucun joueur authentifié.');

            return false;
        }

        return match ($attribute) {
            self::VIEW => self::seenBy($subject, $playerId, $vote),
            self::EDIT, self::DISSOLVE => self::ledBy($subject, $playerId, $vote),
            default => false,
        };
    }

    private static function seenBy(Guild $guild, Uuid $playerId, ?Vote $vote): bool
    {
        if ($guild->hasMember($playerId)) {
            return true;
        }

        $vote?->addReason('Ce joueur n\'est pas membre de cette guilde.');

        return false;
    }

    private static function ledBy(Guild $guild, Uuid $playerId, ?Vote $vote): bool
    {
        if ($guild->isFoundedBy($playerId)) {
            return true;
        }

        $vote?->addReason('Ce joueur n\'est pas le fondateur de cette guilde.');

        return false;
    }

    /**
     * L'UUID du compte, tel que le claim `sub` du JWT le porte. Un identifiant qui n'est
     * pas un UUID ne peut pas être un joueur : on refuse plutôt que de lever, un voter
     * n'ayant pas à casser une requête qu'il pouvait simplement ne pas autoriser.
     */
    private static function playerOf(TokenInterface $token): ?Uuid
    {
        $identifier = $token->getUserIdentifier();

        return Uuid::isValid($identifier) ? Uuid::fromString($identifier) : null;
    }
}
