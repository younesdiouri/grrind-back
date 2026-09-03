<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\EmailBelongsToAnotherAccount;
use App\Identity\Domain\Exception\SocialProfileIncomplete;
use App\Identity\Domain\Exception\SocialSignInRejected;
use App\Identity\Domain\SocialIdentity;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\SocialIdentityRepository;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Domain\Locale;
use App\Shared\Domain\Timezone;
use Psr\Clock\ClockInterface;

/**
 * Connexion — ou inscription, c'est le même geste côté client — par Google ou Apple.
 * Trois cas, et l'ordre compte :
 *
 *  1. **(fournisseur, sub) déjà connu** : on reconnaît le joueur, sans consulter
 *     l'adresse, qui a pu changer chez le fournisseur ;
 *  2. **adresse vérifiée correspondant à un compte existant** : on relie ;
 *  3. sinon, nouveau compte sans mot de passe.
 *
 * Une adresse **non vérifiée** ne relie jamais rien : ce serait offrir la prise de
 * contrôle d'un compte à quiconque sait créer une adresse chez le fournisseur.
 */
final readonly class SignInWithProviderHandler
{
    public function __construct(
        private SocialProfileResolver $profiles,
        private SocialIdentityRepository $identities,
        private UserRepository $users,
        private IssueTokens $issueTokens,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SocialSignInRejected
     * @throws SocialProfileIncomplete
     * @throws EmailBelongsToAnotherAccount
     */
    public function __invoke(SignInWithProvider $command): AuthenticatedUser
    {
        $profile = $this->profiles->resolve(
            $command->provider,
            $command->code,
            $command->redirectUri,
            $command->codeVerifier,
        );

        $user = $this->existing($profile) ?? $this->create($profile, $command->timezone, $command->locale);

        return new AuthenticatedUser($user, ($this->issueTokens)($user));
    }

    /**
     * @throws EmailBelongsToAnotherAccount
     */
    private function existing(SocialProfile $profile): ?User
    {
        $identity = $this->identities->ofSubject($profile->provider, $profile->subject);

        if (null !== $identity) {
            return $identity->user();
        }

        if (null === $profile->email) {
            return null;
        }

        $onThatEmail = $this->users->ofEmail($profile->email);

        if (null === $onThatEmail) {
            return null;
        }

        if (!$profile->emailVerified) {
            throw new EmailBelongsToAnotherAccount($profile->provider);
        }

        $this->link($onThatEmail, $profile);

        return $onThatEmail;
    }

    /**
     * @throws SocialProfileIncomplete
     */
    private function create(SocialProfile $profile, string $timezone, Locale $locale): User
    {
        if (null === $profile->email) {
            throw new SocialProfileIncomplete($profile->provider);
        }

        $user = User::register(
            $profile->email,
            self::displayNameFor($profile),
            Timezone::fromString($timezone),
            $this->clock->now(),
            $locale,
        );

        // Pas de setPassword() : `/api/auth/login` refusera ce compte, c'est voulu.
        $this->users->add($user);
        $this->link($user, $profile);

        return $user;
    }

    private function link(User $user, SocialProfile $profile): void
    {
        $this->identities->add(new SocialIdentity(
            $user,
            $profile->provider,
            $profile->subject,
            $profile->email,
            $this->clock->now(),
        ));
        $this->identities->commit();
    }

    /**
     * Apple ne donne le nom qu'à la toute première autorisation, jamais ensuite. La
     * partie locale de l'adresse est un repli acceptable.
     */
    private static function displayNameFor(SocialProfile $profile): string
    {
        $candidate = trim($profile->displayName ?? '');

        if ('' === $candidate) {
            $candidate = strstr((string) $profile->email, '@', true) ?: 'Joueur';
        }

        return mb_substr($candidate, 0, User::DISPLAY_NAME_MAX_LENGTH);
    }
}
