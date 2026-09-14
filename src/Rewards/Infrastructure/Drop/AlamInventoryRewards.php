<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Drop;

use App\Rewards\Domain\AlamGrant;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\AlamRewards;
use App\Shared\Application\GameRulesets;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

final readonly class AlamInventoryRewards implements AlamRewards
{
    public function __construct(private EntityManagerInterface $manager, private InventoryItemRepository $inventory)
    {
    }

    public function grant(Uuid $runId, int $encounter, Uuid $userId, array $items, DateTimeImmutable $occurredAt, GameRulesets $ruleset): void
    {
        $this->inventory->transactional(function () use ($runId, $encounter, $userId, $items, $occurredAt, $ruleset): void {
            $this->manager->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => $runId->toRfc4122().':alam:'.$encounter.':'.$userId->toRfc4122()]);
            if (null !== $this->manager->getRepository(AlamGrant::class)->findOneBy(['runId' => $runId, 'encounter' => $encounter, 'userId' => $userId])) {
                return;
            }
            $catalog = ItemCatalog::runtime($ruleset);
            foreach ($items as $key => $quantity) {
                if (null === $catalog->findAvailable($key) || $quantity < 1) {
                    throw new LogicException('Le raid ne peut attribuer qu’un objet de son snapshot et une quantité positive.');
                }
                $this->inventory->grantQuantity($userId, $key, $quantity, $occurredAt);
            }
            $this->manager->persist(new AlamGrant($runId, $encounter, $userId, $items, $ruleset->version(), $occurredAt));
        });
    }
}
