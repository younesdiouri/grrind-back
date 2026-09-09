<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La position est attribuée sous le verrou de guilde : l'ordre de lecture suit celui
 * des commits, même si deux processus génèrent leurs UUID avec des horloges différentes.
 * L'auteur reste un identifiant : son départ n'efface pas l'historique de la guilde.
 */
#[ORM\Entity(repositoryClass: GuildMessageRepository::class)]
#[ORM\Table(name: 'community_guild_message')]
#[ORM\UniqueConstraint(name: 'guild_message_position', columns: ['guild_id', 'position'])]
#[ORM\UniqueConstraint(name: 'guild_message_client', columns: ['guild_id', 'author_id', 'client_id'])]
class GuildMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Guild $guild,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $authorId,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $clientId,
        #[ORM\Column]
        private int $position,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column(length: 64)]
        private string $fingerprint,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $imageKey = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function guild(): Guild
    {
        return $this->guild;
    }

    public function authorId(): Uuid
    {
        return $this->authorId;
    }

    public function clientId(): Uuid
    {
        return $this->clientId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function imageKey(): ?string
    {
        return $this->imageKey;
    }
}
