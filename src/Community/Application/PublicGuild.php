<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildEmblemColor;
use App\Community\Domain\GuildEmblemSymbol;
use Symfony\Component\Uid\Uuid;

final readonly class PublicGuild
{
    public function __construct(
        private Uuid $id,
        private string $name,
        private GuildEmblemSymbol $symbol,
        private GuildEmblemColor $background,
        private GuildEmblemColor $foreground,
        private bool $recruitmentOpen,
        private int $memberCount,
    ) {
    }

    /** @return array{id: string, name: string, emblem: array<string, string>, recruitmentOpen: bool, memberCount: int, capacity: int} */
    public function toArray(int $capacity): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'emblem' => ['symbol' => $this->symbol->value, 'backgroundColor' => $this->background->value, 'symbolColor' => $this->foreground->value],
            'recruitmentOpen' => $this->recruitmentOpen,
            'memberCount' => $this->memberCount,
            'capacity' => $capacity,
        ];
    }
}
