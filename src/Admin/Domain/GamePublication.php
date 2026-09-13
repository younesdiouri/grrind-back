<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Archive immuable : les simulations et l'audit retrouvent les règles effectivement publiées. */
#[ORM\Entity]
#[ORM\Table(name: 'game_publication')]
class GamePublication
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $publishedAt;
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        #[ORM\Column]
        private int $revision,
        #[ORM\Column(length: 180)]
        private string $author,
        #[ORM\Column(length: 32)]
        private string $version,
        #[ORM\Column(type: Types::JSON)]
        private array $snapshot,
    ) {
        $this->id = Uuid::v7();
        $this->publishedAt = new DateTimeImmutable();
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->snapshot;
    }
}
