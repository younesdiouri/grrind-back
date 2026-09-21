<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Shared\Application\Teammates;
use Symfony\Component\Uid\Uuid;

/**
 * La réponse de `Community` à {@see Teammates} : deux joueurs sont co-équipiers s'ils
 * partagent une guilde.
 *
 * Un délégué de trois lignes plutôt qu'un `implements` posé sur le dépôt : ce que le port
 * promet — un booléen, sans notion de guilde — et ce que le dépôt sait faire sont deux
 * contrats distincts, et le jour où « co-équipier » recouvrira autre chose qu'une adhésion
 * commune (une ligue, une équipe de tournoi), c'est ici que la clause s'ajoutera, sans
 * rouvrir une requête Doctrine.
 */
final readonly class GuildTeammates implements Teammates
{
    public function __construct(private GuildMembershipRepository $memberships)
    {
    }

    public function share(Uuid $left, Uuid $right): bool
    {
        return $this->memberships->shareAGuild($left, $right);
    }
}
