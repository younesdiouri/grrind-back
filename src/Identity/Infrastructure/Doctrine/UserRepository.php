<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Exception\EmailAlreadyUsed;
use App\Identity\Domain\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le dépôt des comptes, et le user provider du firewall.
 *
 * `UserLoaderInterface` est le point d'extension prévu par Symfony pour charger un
 * compte autrement que par un `findOneBy` sur une colonne
 * (https://symfony.com/doc/current/security/user_providers.html). On s'en sert ici
 * pour deux raisons qu'un `entity.property` ne saurait pas couvrir :
 *
 *  - deux firewalls, deux natures d'identifiant. `^/api/auth/login` présente une
 *    adresse e-mail ; `^/api` présente l'UUID lu dans le claim `sub`. Un seul
 *    provider les sert tous les deux.
 *  - l'adresse est normalisée avant lecture, comme elle l'a été avant écriture.
 *    Sans ça, « BOB@Grrind.app » ne retrouverait pas le compte de « bob@grrind.app ».
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        // Un UUID vient forcément d'un jeton que nous avons signé ; une adresse
        // vient du corps d'un login. Les deux formes ne se recouvrent pas.
        if (Uuid::isValid($identifier)) {
            return $this->ofId(Uuid::fromString($identifier));
        }

        return $this->ofEmail($identifier);
    }

    public function ofId(Uuid $id): ?User
    {
        return $this->find($id);
    }

    public function ofEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->ofEmail($email);
    }

    /**
     * Écrit le compte immédiatement. L'unicité de l'adresse est un invariant du
     * dépôt : c'est lui qui possède l'index unique, donc c'est lui qui traduit une
     * collision en erreur métier plutôt que de laisser fuir une exception SQL.
     *
     * @throws EmailAlreadyUsed
     */
    public function add(User $user): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($user);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées sur la même adresse : la vérification
            // applicative les a laissées passer toutes les deux, l'index unique
            // tranche. C'est le seul endroit qui sait ce que la contrainte protège.
            throw new EmailAlreadyUsed($user->email());
        }
    }

    /**
     * Écrit les modifications d'un compte déjà connu (renommage, fuseau, rehash).
     */
    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Rehash opportuniste, appelé par Symfony après un login réussi quand
     * l'algorithme ou le coût configurés ont évolué. Le mot de passe en clair ne
     * repassera pas de sitôt : c'est la seule occasion de rattraper un vieux hash.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Compte non géré : "%s".', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->commit();
    }
}
