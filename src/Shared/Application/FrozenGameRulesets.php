<?php

declare(strict_types=1);

namespace App\Shared\Application;

/** Snapshot déjà capturé : le laboratoire réutilise les règles sans ouvrir de lecture runtime. */
final readonly class FrozenGameRulesets implements GameRulesets
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data, private string $contentVersion, private int $contentRevision = 0)
    {
    }

    public function snapshot(): array
    {
        return $this->data;
    }

    public function version(): string
    {
        return $this->contentVersion;
    }

    public function revision(): int
    {
        return $this->contentRevision;
    }
}
