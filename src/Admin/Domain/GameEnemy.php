<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'game_enemy')]
#[ORM\UniqueConstraint(name: 'uniq_game_enemy_key', columns: ['enemy_key'])]
class GameEnemy
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(name: 'enemy_key', length: 64)] private string $key;
    #[ORM\Column] private bool $active = true;
    #[ORM\Column(name: 'ever_published_active')] private bool $everPublishedActive = false;
    #[ORM\Column(name: 'sort_order')] private int $sortOrder = 0;
    #[ORM\Column] private bool $boss = false;
    #[ORM\Column(name: 'minimum_level')] private int $minimumLevel = 1;
    #[ORM\Column] private int $hp = 1;
    #[ORM\Column] private int $damage = 0;
    #[ORM\Column(name: 'mitigation_permille')] private int $mitigationPermille = 0;
    #[ORM\Column(name: 'extra_turn_permille')] private int $extraTurnPermille = 0;
    #[ORM\Column(name: 'dodge_permille')] private int $dodgePermille = 0;
    #[ORM\Column(name: 'idle_image_path', length: 255, nullable: true)] private ?string $idleImagePath = null;
    #[ORM\Column(name: 'attack_image_path', length: 255, nullable: true)] private ?string $attackImagePath = null;
    #[ORM\Column(name: 'hit_image_path', length: 255, nullable: true)] private ?string $hitImagePath = null;
    /** @var array{fr: array{name: string, introduction?: ?string}, en: array{name: string, introduction?: ?string}} */
    #[ORM\Column(type: Types::JSON)] private array $translations = ['fr' => ['name' => ''], 'en' => ['name' => '']];
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->key ?? '';
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function wasEverPublishedActive(): bool
    {
        return $this->everPublishedActive;
    }

    public function markPublishedActive(): void
    {
        $this->everPublishedActive = true;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function isBoss(): bool
    {
        return $this->boss;
    }

    public function setBoss(bool $boss): void
    {
        $this->boss = $boss;
    }

    public function getMinimumLevel(): int
    {
        return $this->minimumLevel;
    }

    public function setMinimumLevel(int $minimumLevel): void
    {
        $this->minimumLevel = $minimumLevel;
    }

    public function getHp(): int
    {
        return $this->hp;
    }

    public function setHp(int $hp): void
    {
        $this->hp = $hp;
    }

    public function getDamage(): int
    {
        return $this->damage;
    }

    public function setDamage(int $damage): void
    {
        $this->damage = $damage;
    }

    public function getMitigationPermille(): int
    {
        return $this->mitigationPermille;
    }

    public function setMitigationPermille(int $value): void
    {
        $this->mitigationPermille = $value;
    }

    public function getExtraTurnPermille(): int
    {
        return $this->extraTurnPermille;
    }

    public function setExtraTurnPermille(int $value): void
    {
        $this->extraTurnPermille = $value;
    }

    public function getDodgePermille(): int
    {
        return $this->dodgePermille;
    }

    public function setDodgePermille(int $value): void
    {
        $this->dodgePermille = $value;
    }

    public function getIdleImagePath(): ?string
    {
        return $this->idleImagePath;
    }

    public function setIdleImagePath(?string $path): void
    {
        $this->idleImagePath = '' === $path ? null : $path;
    }

    public function getAttackImagePath(): ?string
    {
        return $this->attackImagePath;
    }

    public function setAttackImagePath(?string $path): void
    {
        $this->attackImagePath = '' === $path ? null : $path;
    }

    public function getHitImagePath(): ?string
    {
        return $this->hitImagePath;
    }

    public function setHitImagePath(?string $path): void
    {
        $this->hitImagePath = '' === $path ? null : $path;
    }

    /** @return array{fr: array{name: string, introduction?: ?string}, en: array{name: string, introduction?: ?string}} */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /** @param array{fr: array{name: string, introduction?: ?string}, en: array{name: string, introduction?: ?string}} $translations */
    public function setTranslations(array $translations): void
    {
        foreach ($translations as &$translation) {
            $introduction = $translation['introduction'] ?? null;
            $translation['introduction'] = null === $introduction || 1 === preg_match('/^[\s\p{Z}]*$/u', $introduction) ? null : $introduction;
        }
        unset($translation);
        $this->translations = $translations;
    }

    #[ORM\PreUpdate]
    public function refuseKeyRename(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('key')) {
            throw new LogicException('La clé métier d’un ennemi est immuable après sa création.');
        }
    }
}
