<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\XpCalculator;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;

/**
 * Lecture pédagogique du snapshot publié, sans état joueur ni nouvel estimateur (#354).
 * Référence : 30 minutes de durée déjà retenue, journée vide, sans métriques ni bonus.
 * Le calculateur applique le plafond publié et son arrondi habituel ; ce n'est pas une
 * promesse de crédit à l'import. Une activité absente du mapping de sa source reste non
 * prise en charge (sports de combat : #251). Marche et pas ne se convertissent pas en XP ;
 * l'énergie active quotidienne peut bonifier la Vitality, dans une autre lecture.
 *
 * @phpstan-type Mapping array{source: string, activityType: string, sport: ?string}
 * @phpstan-type Entry array{discipline: string, name: string, aliases: list<string>, providerMappings: list<Mapping>, creditsXp: bool, attributePercentages: ?array<string, int>, baseXp: int, baseAttributeGains: ?array{strength: int, endurance: int, mobility: int, dexterity: int}, dailyCapXp: ?int}
 */
final readonly class SportCatalogResource
{
    private const int DURATION_SECONDS = 1800;

    /** @param list<Entry> $disciplines */
    private function __construct(
        public string $rulesetVersion,
        public int $revision,
        public array $disciplines,
    ) {
    }

    public static function from(GameRulesets $rulesets, XpCalculator $calculator, DisciplineTranslator $translator, string $locale): self
    {
        $snapshot = $rulesets->snapshot();
        /** @var list<array{source: string, provider_type: string, normalized_sport?: ?string, discipline: string, active: bool}> $activityTypes */
        $activityTypes = $snapshot['activity_types'];
        /** @var array<string, list<Mapping>> $mappings */
        $mappings = [];
        foreach ($activityTypes as $activity) {
            if ($activity['active']) {
                $mappings[$activity['discipline']][] = [
                    'source' => $activity['source'],
                    'activityType' => $activity['provider_type'],
                    'sport' => $activity['normalized_sport'] ?? null,
                ];
            }
        }

        /** @var list<array{discipline: string, active: bool, credits_xp: bool, daily_cap_xp: ?int}> $rows */
        $rows = $snapshot['disciplines'];
        $entries = [];
        foreach ($rows as $row) {
            if (!$row['active']) {
                continue;
            }
            $discipline = Discipline::from($row['discipline']);
            $providers = $mappings[$discipline->value] ?? [];
            $aliases = [];
            foreach ($providers as $provider) {
                $aliases[] = $provider['activityType'];
                if (null !== $provider['sport']) {
                    $aliases[] = $provider['sport'];
                }
            }
            $aliases = array_values(array_unique($aliases));
            sort($aliases);
            $award = $row['credits_xp']
                ? $calculator->calculate($discipline, self::DURATION_SECONDS, [], DailyLoad::untouched())
                : null;
            $entries[] = [
                'discipline' => $discipline->value,
                'name' => $translator->labelOf($discipline, $locale),
                'aliases' => $aliases,
                'providerMappings' => $providers,
                'creditsXp' => $row['credits_xp'],
                'attributePercentages' => null === $award ? null : array_change_key_case($award->attributePercentages),
                'baseXp' => $award?->amount() ?? 0,
                'baseAttributeGains' => $award?->attributeGains->toArray(),
                'dailyCapXp' => $row['credits_xp'] ? $row['daily_cap_xp'] : null,
            ];
        }

        return new self($rulesets->version(), $rulesets->revision(), $entries);
    }

    /** @return array{rulesetVersion: string, revision: int, reference: array{durationSeconds: int, unit: string}, disciplines: list<Entry>} */
    public function toArray(): array
    {
        return [
            'rulesetVersion' => $this->rulesetVersion,
            'revision' => $this->revision,
            'reference' => ['durationSeconds' => self::DURATION_SECONDS, 'unit' => 'XP'],
            'disciplines' => $this->disciplines,
        ];
    }
}
