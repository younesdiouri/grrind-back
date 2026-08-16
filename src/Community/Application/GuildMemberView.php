<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildRole;
use App\Shared\Application\PlayerProfile;
use App\Shared\Application\PlayerProgression;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Un membre, complet : ce que `Community` sait de lui (son rôle, sa date d'entrée) et ce
 * que les deux ports en ont rapporté.
 *
 * Les trois morceaux restent distincts au lieu d'être aplatis en un seul objet : c'est ce
 * qui rend visible, à la lecture, ce qui appartient à la guilde et ce qui appartient à
 * d'autres modules.
 */
final readonly class GuildMemberView
{
    public function __construct(
        public Uuid $playerId,
        public PlayerProfile $profile,
        public PlayerProgression $progression,
        public GuildRole $role,
        public DateTimeImmutable $joinedAt,
    ) {
    }
}
