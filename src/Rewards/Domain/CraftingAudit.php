<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** La recette et les quantités réellement échangées restent auditables après publication. */
#[ORM\Entity]
#[ORM\Table(name: 'rewards_crafting_audit')]
#[ORM\UniqueConstraint(name: 'uniq_crafting_request_key', columns: ['request_key'])]
class CraftingAudit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $receipt = [];

    /** @param array<string, int> $costs */
    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
        #[ORM\Column(length: 64)]
        private string $recipeKey,
        #[ORM\Column(length: 64)]
        private string $resultKey,
        #[ORM\Column]
        private int $quantity,
        #[ORM\Column(type: Types::JSON)]
        private array $costs,
        #[ORM\Column(length: 64)]
        private string $rulesetVersion,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $craftedAt,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $requestKey = null,
    ) {
        $this->id = Uuid::v7();
    }

    /** @param array<string, mixed> $receipt */
    public function rememberReceipt(array $receipt): void
    {
        $this->receipt = $receipt;
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return $this->receipt;
    }

    public function recipeKey(): string
    {
        return $this->recipeKey;
    }

    public function id(): Uuid
    {
        return $this->id;
    }
}
