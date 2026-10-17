<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Catalogue cosmétique indépendant des règles de jeu ; les versions gardent des URL distinctes. */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'game_artwork_pack')]
#[ORM\UniqueConstraint(name: 'uniq_artwork_pack', columns: ['kind', 'pack_key', 'version'])]
class GameArtworkPack
{
    public const array POSES = ['idle', 'attack', 'hit', 'critical', 'dodge', 'combo', 'replay'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'pack_key', length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z][A-Z0-9_]{0,63}$/')]
    private string $key = '';

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: ['AVATAR', 'EQUIPMENT'])]
    private string $kind = 'AVATAR';

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^v[0-9]+$/')]
    private string $version = 'v1';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'manifest_json', type: Types::TEXT)]
    #[Assert\Json]
    private string $manifestJson = '{}';

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->name;
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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

    public function getManifestJson(): string
    {
        return $this->manifestJson;
    }

    public function setManifestJson(string $json): void
    {
        $this->manifestJson = $json;
    }

    #[ORM\PreUpdate]
    public function preserveIdentity(PreUpdateEventArgs $event): void
    {
        foreach (['key', 'kind', 'version'] as $field) {
            if ($event->hasChangedField($field)) {
                throw new LogicException('Créer une nouvelle version plutôt que renommer un pack graphique.');
            }
        }
    }

    #[Assert\Callback]
    public function validateManifest(ExecutionContextInterface $context): void
    {
        $data = json_decode($this->manifestJson, true);
        if (!\is_array($data)) {
            $context->buildViolation('Le manifeste doit être un objet JSON.')->atPath('manifestJson')->addViolation();

            return;
        }
        $error = $this->manifestError($data);
        if (null !== $error) {
            $context->buildViolation($error)->atPath('manifestJson')->addViolation();
        }
    }

    /** @param array<array-key, mixed> $data */
    private function manifestError(array $data): ?string
    {
        if ('AVATAR' === $this->kind) {
            if (!str_ends_with($this->key, '_'.strtoupper($this->version))) {
                return 'La clé d’avatar doit terminer par sa version (par exemple RUNNER_V1).';
            }
            foreach (isset($data['foregroundUrls']) ? ['imageUrls', 'thumbnailUrls', 'foregroundUrls'] : ['imageUrls', 'thumbnailUrls'] as $field) {
                if (!\is_array($data[$field] ?? null)) {
                    return "Série d’images manquante : {$field}.";
                }
                foreach (['back', 'front'] as $view) {
                    if (!\is_array($data[$field][$view] ?? null)) {
                        return "Orientation manquante : {$field}.{$view}.";
                    }
                    foreach (self::POSES as $pose) {
                        if (!self::path($data[$field][$view][$pose] ?? null)) {
                            return "Image manquante ou invalide : {$field}.{$view}.{$pose}.";
                        }
                    }
                }
            }
            if (true !== ($data['pixelArt'] ?? null) || !\is_array($data['attachments'] ?? null)) {
                return 'Le pack rétro doit déclarer pixelArt et les ancrages.';
            }
            foreach (['back', 'front'] as $view) {
                $anchors = $data['attachments'][$view] ?? null;
                if (!\is_array($anchors) || [] === $anchors) {
                    return "Ancrages manquants : {$view}.";
                }
                foreach ($anchors as $anchor => $poses) {
                    if (!\is_string($anchor) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/', $anchor) || !\is_array($poses)) {
                        return 'Nom ou série d’ancrages invalide.';
                    }
                    foreach (self::POSES as $pose) {
                        $point = $poses[$pose] ?? null;
                        if (!\is_array($point) || !self::number($point['x'] ?? null, -0.25, 1.25)
                            || !self::number($point['y'] ?? null, -0.25, 1.25)
                            || !self::number($point['span'] ?? null, 0.001, 1)
                            || !self::number($point['rotate'] ?? null, -360, 360)) {
                            return "Transformation invalide : {$view}.{$anchor}.{$pose}.";
                        }
                    }
                }
            }

            return null;
        }
        if (!\in_array($data['slot'] ?? null, ['HEAD', 'CHEST', 'HANDS', 'LEGS', 'FEET', 'ACCESSORY', 'WEAPON'], true)
            || !self::path($data['iconUrl'] ?? null)
            || !\is_array($data['compatibleAppearances'] ?? null) || [] === $data['compatibleAppearances']
            || !\is_array($data['layers'] ?? null) || [] === $data['layers'] || \count($data['layers']) > 8) {
            return 'Un objet doit déclarer son emplacement, son icône, ses avatars compatibles et ses calques.';
        }
        foreach ($data['compatibleAppearances'] as $key) {
            if (!\is_string($key) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key)) {
                return 'Clé d’avatar compatible invalide.';
            }
        }
        foreach ($data['layers'] as $layer) {
            if (!\is_array($layer) || !\is_string($layer['attachment'] ?? null)
                || !\in_array($layer['plane'] ?? null, ['front', 'behind'], true)
                || !self::number($layer['aspectRatio'] ?? null, 0.01, 100)
                || !self::number($layer['scale'] ?? null, 0.01, 8)
                || !\is_array($layer['pivot'] ?? null)
                || !\is_array($layer['sourceUrls'] ?? null)
                || !self::number($layer['pivot']['x'] ?? null, 0, 1)
                || !self::number($layer['pivot']['y'] ?? null, 0, 1)) {
                return 'Transformation de calque invalide.';
            }
            foreach (['back', 'front'] as $view) {
                if (!self::path($layer['sourceUrls'][$view] ?? null)) {
                    return "Image de calque invalide : {$view}.";
                }
            }
        }

        return null;
    }

    private static function path(mixed $value): bool
    {
        return \is_string($value) && (1 === preg_match('#^/artwork/(?:[a-z0-9_-]+/)+[a-z0-9_-]+\.png$#', $value)
            || 1 === preg_match('#^/game-images/[a-f0-9]{40}\.png$#', $value));
    }

    private static function number(mixed $value, float $minimum, float $maximum): bool
    {
        return (\is_int($value) || \is_float($value)) && is_finite((float) $value) && $value >= $minimum && $value <= $maximum;
    }
}
