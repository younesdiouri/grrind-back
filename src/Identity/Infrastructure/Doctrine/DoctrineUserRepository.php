<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

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
    }
}
