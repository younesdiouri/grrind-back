<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Les entrées et les deux snapshots sont immuables, même après une publication ou un edit
 * du profil. Incrémenter ENGINE_VERSION si un calcul ou l'algorithme RNG change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_design_run')]
class GameDesignRun
{
    public const string ENGINE_VERSION = 'gd1-combat2-mt19937';
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt;
    #[ORM\Column(length: 40)] private string $engineVersion = self::ENGINE_VERSION;
    /** @param array<string, mixed> $input
     * @param array{published: array<string, mixed>, draft: array<string, mixed>} $snapshots
     * @param array<string, mixed>                                                $result
     */
    public function __construct(
        #[ORM\Column(enumType: GameDesignRunKind::class)]
        private GameDesignRunKind $kind,
        #[ORM\Column(type: Types::JSON)]
        private array $input,
        #[ORM\Column(type: Types::JSON)]
        private array $snapshots,
        #[ORM\Column(type: Types::JSON)]
        private array $result,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function kind(): GameDesignRunKind
    {
        return $this->kind;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function engineVersion(): string
    {
        return $this->engineVersion;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    /** @return array{published: array<string, mixed>, draft: array<string, mixed>} */
    public function snapshots(): array
    {
        return $this->snapshots;
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        return $this->result;
    }
}
