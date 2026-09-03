<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use Symfony\Contracts\Service\ResetInterface;

/** Les libellés de disciplines sont du contenu de jeu : le snapshot publié les porte, pas le catalogue Symfony. */
final class DisciplineTranslator implements ResetInterface
{
    /** @var array<string, array<string, string>>|null */
    private ?array $labels = null;

    private ?int $revision = null;

    public function __construct(private readonly GameRulesets $rulesets)
    {
    }

    public function labelOf(Discipline $discipline, string $locale): string
    {
        $labels = $this->labels();

        return $labels[$discipline->value][$locale] ?? $labels[$discipline->value]['en'] ?? $labels[$discipline->value]['fr'] ?? $discipline->value;
    }

    public function reset(): void
    {
        $this->labels = null;
        $this->revision = null;
    }

    /** @return array<string, array<string, string>> */
    private function labels(): array
    {
        $revision = $this->rulesets->revision();
        if (null !== $this->labels && $revision === $this->revision) {
            return $this->labels;
        }

        $snapshot = $this->rulesets->snapshot();
        $rows = $snapshot['disciplines'] ?? [];
        \assert(\is_array($rows));
        $labels = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            \assert(\is_string($row['discipline'] ?? null));
            \assert(\is_array($row['translations'] ?? null));
            $translations = $row['translations'];
            foreach ($translations as $locale => $translation) {
                if (\is_string($locale) && \is_array($translation) && \is_string($translation['label'] ?? null)) {
                    $labels[$row['discipline']][$locale] = $translation['label'];
                }
            }
        }

        $this->revision = $revision;

        return $this->labels = $labels;
    }
}
