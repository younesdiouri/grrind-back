<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Port de hachage. Le domaine ne manipule jamais un mot de passe en clair au-delà
 * de cet appel, et ne sait rien de l'algorithme : celui-ci est amené à changer
 * (bcrypt → argon2id) sans qu'une seule ligne de métier bouge.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $hash, string $plainPassword): bool;

    /**
     * Vrai quand le hash a été produit par un algorithme ou un coût dépassé :
     * on en profite pour le remplacer au prochain login réussi.
     */
    public function needsRehash(string $hash): bool;
}
