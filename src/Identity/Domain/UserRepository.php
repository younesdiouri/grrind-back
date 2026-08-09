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
     * Écrit le compte immédiatement. L'unicité de l'adresse est un invariant du
     * repository : c'est lui qui possède l'index unique, donc c'est lui qui traduit
     * une collision en erreur métier plutôt que de laisser fuir une exception SQL.
     *
     * @throws Exception\EmailAlreadyUsed
     */
    public function add(User $user): void;

    /**
     * Écrit les modifications d'un compte déjà connu (renommage, fuseau, rehash).
     */
    public function commit(): void;
}
