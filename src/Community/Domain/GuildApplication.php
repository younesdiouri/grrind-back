<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\GuildApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Les deux noms et l'UUID de guilde restent lisibles dans le reçu personnel après dissolution. */
#[ORM\Entity(repositoryClass: GuildApplicationRepository::class)]
#[ORM\Table(name: 'community_guild_application')]
#[ORM\UniqueConstraint(name: 'guild_application_pending', columns: ['guild_id', 'player_id'], options: ['where' => "status = 'PENDING'"])]
#[ORM\Index(name: 'guild_application_player_history', columns: ['player_id', 'id'])]
#[ORM\Index(name: 'guild_application_inbox', columns: ['guild_id', 'status', 'id'])]
class GuildApplication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\Column(enumType: ApplicationStatus::class)]
    public ApplicationStatus $status = ApplicationStatus::Pending;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $guildId,
        #[ORM\Column(length: Guild::NAME_MAX_LENGTH)]
        public private(set) string $guildName,
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $playerId,
        #[ORM\Column]
        public private(set) string $displayName,
    ) {
        $this->id = Uuid::v7();
    }

    /** @return array{id: string, guildId: string, guildName: string, playerId: string, displayName: string, status: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'guildId' => $this->guildId->toRfc4122(),
            'guildName' => $this->guildName,
            'playerId' => $this->playerId->toRfc4122(),
            'displayName' => $this->displayName,
            'status' => $this->status->value,
        ];
    }
}
