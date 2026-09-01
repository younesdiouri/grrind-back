<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La forme administrable d'un objet. Les clés restent immuables après insertion : les
 * inventaires et les tirages audités les emploient comme identifiants historiques.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'game_item')]
#[ORM\UniqueConstraint(name: 'uniq_game_item_key', columns: ['item_key'])]
class GameItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'item_key', length: 64)]
    private string $key;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(length: 20)]
    private string $rarity = 'COMMON';

    #[ORM\Column(length: 20)]
    private string $kind = 'EQUIPMENT';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $slot = null;

    #[ORM\Column(name: 'price_coins')]
    private int $priceCoins = 0;

    /** @var list<array{type: string, value: int, discipline?: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $modifiers = [];

    #[ORM\Column(name: 'shop_available')]
    private bool $shopAvailable = false;

    #[ORM\Column(name: 'shop_minimum_level', nullable: true)]
    private ?int $shopMinimumLevel = null;

    /** Le chemin relatif, jamais une URL saisie par l'administrateur. */
    #[ORM\Column(name: 'image_path', length: 255)]
    private string $imagePath = 'placeholder.png';

    /** @var array{fr: array{name: string}, en: array{name: string}} */
    #[ORM\Column(type: Types::JSON)]
    private array $translations = ['fr' => ['name' => ''], 'en' => ['name' => '']];

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getRarity(): string
    {
        return $this->rarity;
    }

    public function setRarity(string $rarity): void
    {
        $this->rarity = $rarity;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getSlot(): ?string
    {
        return $this->slot;
    }

    public function setSlot(?string $slot): void
    {
        $this->slot = $slot;
    }

    public function getPriceCoins(): int
    {
        return $this->priceCoins;
    }

    public function setPriceCoins(int $priceCoins): void
    {
        $this->priceCoins = $priceCoins;
    }

    /** @return list<array{type: string, value: int, discipline?: string}> */
    public function getModifiers(): array
    {
        return $this->modifiers;
    }

    /** @param list<array{type: string, value: int, discipline?: string}> $modifiers */
    public function setModifiers(array $modifiers): void
    {
        $this->modifiers = $modifiers;
    }

    public function isShopAvailable(): bool
    {
        return $this->shopAvailable;
    }

    public function setShopAvailable(bool $shopAvailable): void
    {
        $this->shopAvailable = $shopAvailable;
    }

    public function getShopMinimumLevel(): ?int
    {
        return $this->shopMinimumLevel;
    }

    public function setShopMinimumLevel(?int $shopMinimumLevel): void
    {
        $this->shopMinimumLevel = $shopMinimumLevel;
    }

    public function getImagePath(): string
    {
        return $this->imagePath;
    }

    public function setImagePath(string $imagePath): void
    {
        // EasyAdmin soumet une chaîne vide quand on édite sans nouveau fichier : conserver
        // l'URL publiée évite qu'un volume neuf transforme le placeholder en image cassée.
        if ('' !== $imagePath) {
            $this->imagePath = $imagePath;
        }
    }

    /** @return array{fr: array{name: string}, en: array{name: string}} */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /** @param array{fr: array{name: string}, en: array{name: string}} $translations */
    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }

    #[ORM\PreUpdate]
    public function refuseKeyRename(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('key')) {
            throw new LogicException('La clé métier d’un item est immuable après sa création.');
        }
    }
}
