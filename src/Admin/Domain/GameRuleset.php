<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Le snapshot publié est la seule lecture runtime ; les tables éditables ne le sont jamais directement. */
#[ORM\Entity]
#[ORM\Table(name: 'game_ruleset')]
class GameRuleset
{
    #[ORM\Id] #[ORM\Column] private int $id = 1;
    #[ORM\Column] private int $revision = 1;
    #[ORM\Column(length: 32)] private string $version = '';
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $snapshot = [];
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $publishedAt;
    public function __construct()
    {
        $this->publishedAt = new DateTimeImmutable();
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function publish(array $snapshot, string $version): void
    {
        ++$this->revision;
        $this->snapshot = $snapshot;
        $this->version = $version;
        $this->publishedAt = new DateTimeImmutable();
    }
}
