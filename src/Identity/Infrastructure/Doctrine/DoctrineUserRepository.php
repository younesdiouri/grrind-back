<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\Exception\EmailAlreadyUsed;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(UserRepository::class)]
final readonly class DoctrineUserRepository implements UserRepository
{
    /**
     * @var EntityRepository<User>
     */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(User::class);
    }

    public function ofId(Uuid $id): ?User
    {
        return $this->repository->find($id);
    }

    public function ofEmail(Email $email): ?User
    {
        return $this->repository->findOneBy(['email' => $email]);
    }

    public function emailExists(Email $email): bool
    {
        return null !== $this->ofEmail($email);
    }

    public function add(User $user): void
    {
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées sur la même adresse : la vérification
            // applicative les a laissées passer toutes les deux, l'index unique
            // tranche. C'est le seul endroit qui sait ce que la contrainte protège.
            throw new EmailAlreadyUsed($user->email());
        }
    }

    public function commit(): void
    {
        $this->entityManager->flush();
    }
}
