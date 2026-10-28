<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Flux relisible indépendant de la réponse d'import, jamais réécrite. */
#[ORM\Entity]
#[ORM\Table(name: 'together_bonus_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_together_workout_multiplier', columns: ['workout_id', 'multiplier'])]
#[ORM\Index(name: 'idx_together_receipt_user_id', columns: ['user_id', 'id'])]
class TogetherBonusReceipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $id,
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $userId,
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $workoutId,
        #[ORM\Column]
        public int $multiplier,
        #[ORM\Column(type: Types::JSON)]
        public array $payload,
    ) {
    }
}
