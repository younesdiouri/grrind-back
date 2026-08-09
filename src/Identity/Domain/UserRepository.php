<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Port de persistance des comptes. Le domaine décrit ce dont il a besoin ;
 * l'implémentation Doctrine vit dans Infrastructure et reste remplaçable.
 */
interface UserRepository
{
    public function ofId(Uuid $id): ?User;

    public function ofEmail(Email $email): ?User;

    public function emailExists(Email $email): bool;

    /**
     * Ajoute le compte sans flusher : c'est l'appelant qui décide des frontières
     * transactionnelles, pas le repository.
     */
    public function add(User $user): void;
}
