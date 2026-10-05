<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Appearance;
use DateTimeImmutable;

/**
 * Ce qu'un autre joueur a le droit de savoir d'un compte : comment il s'appelle, depuis
 * quand il est là, et à quoi ressemble son héros (#289).
 *
 * **Ce qui n'y est pas est la moitié du contrat.** Ni adresse, ni fuseau, ni rôle : ce sont
 * des données de compte, pas de profil public. Le port ne les rend pas, donc aucune route
 * ne peut les laisser fuir par distraction — c'est plus sûr qu'une ressource HTTP qui
 * penserait à les omettre.
 */
final readonly class PlayerProfile
{
    public function __construct(
        public string $displayName,
        public DateTimeImmutable $registeredAt,
        public Appearance $appearance = Appearance::Murid,
    ) {
    }
}
