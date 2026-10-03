<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Domain\StreakChest;
use App\Engagement\Domain\StreakRules;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\SessionStreak;
use App\Shared\Application\SessionStreaks;
use App\Shared\Application\SportSessions;
use App\Shared\Application\StreakChests;
use App\Shared\Application\StreakState;
use App\Shared\Domain\Event\WorkoutImported;
use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La série de régularité (#286), relue à chaque séance depuis les jours sportifs — rien
 * n'est stocké de la série elle-même, seulement les coffres qu'elle a rapportés.
 *
 * **Un état dérivé plutôt qu'un compteur.** Les séances arrivent en retard et dans le
 * désordre : un import de dix jours, un second appareil qui remonte la veille. Un compteur
 * incrémenté devrait se réparer à chaque fois ; une relecture est juste par construction.
 *
 * **Évaluée à la date du sport**, comme tout le reste de la transaction d'import : une
 * séance d'il y a trois jours se juge sur la série de ce jour-là, et les séances plus
 * tardives déjà en base n'y entrent pas.
 */
final readonly class StreakTracker implements SessionStreaks
{
    public function __construct(
        private SportSessions $sessions,
        private PlayerTimezones $timezones,
        private StreakChests $chests,
        private GameRulesets $rulesets,
        private EntityManagerInterface $manager,
        private ClockInterface $clock,
    ) {
    }

    public function recordFor(WorkoutImported $workout): SessionStreak
    {
        $timezone = $this->timezones->of($workout->userId);
        $day = LocalDay::containing($workout->startedAt, $timezone)->date;
        $sessions = $this->sessions->of($workout->userId);
        $id = $workout->workoutId->toRfc4122();
        $totals = self::totals($sessions, $timezone);
        $without = self::totals(array_filter($sessions, static fn (array $session): bool => $session['id'] !== $id), $timezone);

        $before = StreakRules::evaluate($this->sportDays($without), $day);
        $after = StreakRules::evaluate($this->sportDays($totals), $day);

        return new SessionStreak($day, isset($this->sportDays($totals)[$day]), $before, $after, $this->grantChests($workout, $after));
    }

    public function current(Uuid $player): StreakState
    {
        $timezone = $this->timezones->of($player);

        return StreakRules::evaluate($this->sportDays(self::totals($this->sessions->of($player), $timezone)), LocalDay::containing($this->clock->now(), $timezone)->date);
    }

    /** @return list<\App\Shared\Application\DroppedItem> */
    private function grantChests(WorkoutImported $workout, StreakState $after): array
    {
        if (null === $after->startedOn || 0 === $after->weeksCompleted) {
            return [];
        }
        $runStartedOn = new DateTimeImmutable($after->startedOn);
        $granted = [];
        /** @var list<StreakChest> $existing */
        $existing = $this->manager->getRepository(StreakChest::class)->findBy(['userId' => $workout->userId, 'runStartedOn' => $runStartedOn]);
        foreach ($existing as $chest) {
            $granted[$chest->week()] = true;
        }

        $chests = [];
        for ($week = 1; $week <= $after->weeksCompleted; ++$week) {
            if (isset($granted[$week])) {
                continue;
            }
            $rarity = StreakRules::rarityOf($week);
            $itemKey = $this->settings()['chests'][$rarity] ?? throw new LogicException(\sprintf('Aucun coffre de streak publié pour la rareté %s.', $rarity));
            $chests[] = $this->chests->grant($workout->userId, $itemKey, $workout->occurredAt());
            $this->manager->persist(new StreakChest($workout->userId, $runStartedOn, $week, $rarity, $itemKey, $this->rulesets->version(), $workout->occurredAt()));
        }
        $this->manager->flush();

        return $chests;
    }

    /**
     * @param array<array{id: string, startedAt: DateTimeImmutable, seconds: int}> $sessions
     *
     * @return array<string, int> secondes de sport par jour local
     */
    private static function totals(array $sessions, Timezone $timezone): array
    {
        $totals = [];
        foreach ($sessions as $session) {
            $day = LocalDay::containing($session['startedAt'], $timezone)->date;
            $totals[$day] = ($totals[$day] ?? 0) + $session['seconds'];
        }

        return $totals;
    }

    /**
     * @param array<string, int> $totals
     *
     * @return array<string, true>
     */
    private function sportDays(array $totals): array
    {
        $minimum = $this->settings()['minimum_daily_seconds'];

        return array_map(static fn (): bool => true, array_filter($totals, static fn (int $seconds): bool => $seconds >= $minimum));
    }

    /** @return array{minimum_daily_seconds: int, chests: array<string, string>} */
    private function settings(): array
    {
        /** @var array{minimum_daily_seconds: int, chests: array<string, string>} $streak */
        $streak = $this->rulesets->snapshot()['streak'] ?? throw new LogicException('Le réglage publié "streak" est absent. Appliquer les migrations.');

        return $streak;
    }
}
