<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildRole;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Une guilde telle que son membre la reçoit.
 *
 * **`role` est celui de l'appelant, pas une propriété de la guilde.** C'est ce qui permet
 * au client de décider quoi afficher — le bouton « dissoudre » n'existe que pour le
 * fondateur — sans rejouer les règles du voter côté app. Le serveur reste seul juge : le
 * client s'en sert pour dessiner, jamais pour autoriser.
 *
 * `capacity` accompagne `memberCount` parce que l'écran affiche « 12 / 30 » : donner le
 * numérateur sans le dénominateur obligerait le client à embarquer une constante qui est
 * de l'équilibrage, donc à sortir de l'App Store pour la corriger.
 */
final readonly class GuildResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $createdAt,
        public int $memberCount,
        public int $capacity,
        public GuildRole $role,
    ) {
    }

    public static function from(Guild $guild, Uuid $playerId, int $capacity): self
    {
        return new self(
            $guild->id()->toRfc4122(),
            $guild->name(),
            $guild->createdAt()->format(DateTimeInterface::ATOM),
            $guild->size(),
            $capacity,
            // Le resolver a déjà refusé un non-membre : arriver ici sans adhésion est
            // impossible, et le repli n'existe que pour que le type le dise.
            $guild->membershipOf($playerId)?->role() ?? GuildRole::Member,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'createdAt' => $this->createdAt,
            'memberCount' => $this->memberCount,
            'capacity' => $this->capacity,
            'role' => $this->role->value,
        ];
    }
}
