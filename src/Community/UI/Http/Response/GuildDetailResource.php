<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\GuildMemberView;
use App\Community\Domain\Guild;
use Symfony\Component\Uid\Uuid;

/**
 * La guilde et ses membres, d'un bloc. **C'est l'écran** : c'est à ça que la guilde sert en
 * v1 — voir qui est là et où chacun en est.
 *
 * Un seul appel et non « la guilde puis ses membres » : l'onglet ne s'ouvre jamais sans les
 * deux, et les séparer coûterait un aller-retour pour un écran qui n'a rien à afficher
 * entre-temps.
 */
final readonly class GuildDetailResource
{
    /**
     * @param list<GuildMemberResource> $members
     */
    public function __construct(
        public GuildResource $guild,
        public array $members,
    ) {
    }

    /**
     * @param list<GuildMemberView> $members
     */
    public static function from(Guild $guild, array $members, Uuid $playerId, int $capacity): self
    {
        return new self(
            GuildResource::from($guild, $playerId, $capacity),
            array_map(GuildMemberResource::from(...), $members),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->guild->toArray() + [
            // L'ordre est décidé par l'agrégat — fondateur d'abord, puis par date d'entrée —
            // et jamais celui que rend la base. Une liste qui se réordonne toute seule entre
            // deux ouvertures d'écran est un bug qu'on ne sait pas reproduire.
            'members' => array_map(static fn (GuildMemberResource $member): array => $member->toArray(), $this->members),
        ];
    }
}
