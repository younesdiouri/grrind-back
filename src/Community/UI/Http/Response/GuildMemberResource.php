<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\GuildMemberView;
use App\Community\Domain\GuildRole;
use DateTimeInterface;

/**
 * Un membre : le joueur public, plus ce que la guilde sait de lui.
 *
 * Les champs de {@see PlayerResource} sont **étalés** et non imbriqués sous une clé
 * `player`. Le client dessine une ligne, pas deux objets, et `GET /api/players/{id}` sert
 * exactement le même bloc — l'imbrication l'obligerait à déballer d'un côté et pas de
 * l'autre. En OpenAPI, c'est un `allOf` : le type reste dérivé, pas recopié.
 */
final readonly class GuildMemberResource
{
    public function __construct(
        public PlayerResource $player,
        public GuildRole $role,
        public string $joinedAt,
    ) {
    }

    public static function from(GuildMemberView $member): self
    {
        return new self(
            PlayerResource::from($member->playerId, $member->profile, $member->progression),
            $member->role,
            $member->joinedAt->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->player->toArray() + [
            'role' => $this->role->value,
            'joinedAt' => $this->joinedAt,
        ];
    }
}
