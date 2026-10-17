<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\ArtworkCatalog;
use App\Shared\Domain\Appearance;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Contracts\Service\ResetInterface;

/** Les versions inactives restent lisibles pour les profils et replays déjà référencés. */
final class DatabaseArtworkCatalog implements ArtworkCatalog, ResetInterface
{
    /** @var array{appearances: list<array<string, mixed>>, equipment: list<array<string, mixed>>}|null */
    private ?array $catalog = null;

    public function __construct(private readonly Connection $connection, private readonly UrlHelper $urls)
    {
    }

    public function all(): array
    {
        if (null !== $this->catalog) {
            return $this->catalog;
        }
        $appearances = [];
        $equipment = [];
        foreach ($this->connection->fetchAllAssociative('SELECT pack_key, kind, version, name, active, manifest_json FROM game_artwork_pack ORDER BY sort_order, pack_key, LENGTH(version) DESC, version DESC') as $row) {
            /** @var array{pack_key:string, kind:string, version:string, name:string, active:bool, manifest_json:string} $row */
            /** @var array<string, mixed> $manifest Validé par le formulaire ou la commande d’import. */
            $manifest = json_decode((string) $row['manifest_json'], true, flags: \JSON_THROW_ON_ERROR);
            if ('AVATAR' === $row['kind'] && 'guard' === ($manifest['stanceMode'] ?? null)) {
                $manifest = $this->expandGuard($manifest);
            }
            $entry = ['key' => (string) $row['pack_key'], 'version' => (string) $row['version'],
                'name' => (string) $row['name'], 'selectable' => (bool) $row['active']] + $manifest;
            /** @var array<string, mixed> $entry */
            $entry = $this->absoluteUrls($entry);
            if ('AVATAR' === $row['kind']) {
                $appearances[] = $entry;
            } else {
                $equipment[] = $entry;
            }
        }

        return $this->catalog = ['appearances' => $appearances, 'equipment' => $equipment];
    }

    public function selectable(string $appearance): bool
    {
        foreach ($this->all()['appearances'] as $model) {
            if ($model['key'] === $appearance && true === $model['selectable']) {
                return true;
            }
        }

        return false;
    }

    public function equipmentVersion(string $itemKey): ?string
    {
        foreach ($this->all()['equipment'] as $item) {
            if ($item['key'] === $itemKey && true === $item['selectable']) {
                return \is_string($item['version']) ? $item['version'] : null;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->catalog = null;
    }

    /**
     * Une seule calibration administrable ; le contrat public conserve ses sept états.
     *
     * @param array<string, mixed> $manifest validé à l’écriture du pack
     *
     * @return array<string, mixed>
     */
    private function expandGuard(array $manifest): array
    {
        foreach (isset($manifest['foregroundUrls']) ? ['imageUrls', 'thumbnailUrls', 'foregroundUrls'] : ['imageUrls', 'thumbnailUrls'] as $field) {
            /** @var array<string, array{idle:string}> $views */
            $views = $manifest[$field];
            foreach ($views as $view => $series) {
                $views[$view] = array_fill_keys(Appearance::POSES, $series['idle']);
            }
            $manifest[$field] = $views;
        }
        /** @var array<string, array<string, array{idle:array<string, int|float>}>> $attachments */
        $attachments = $manifest['attachments'];
        foreach ($attachments as &$anchors) {
            foreach ($anchors as &$series) {
                $series = array_fill_keys(Appearance::POSES, $series['idle']);
            }
            unset($series);
        }
        unset($anchors);
        $manifest['attachments'] = $attachments;
        unset($manifest['stanceMode']);

        return $manifest;
    }

    private function absoluteUrls(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->absoluteUrls(...), $value);
        }

        return \is_string($value) && (str_starts_with($value, '/artwork/') || str_starts_with($value, '/game-images/')) ? $this->urls->getAbsoluteUrl($value) : $value;
    }
}
