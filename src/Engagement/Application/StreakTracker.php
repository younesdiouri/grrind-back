<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Domain\StreakRules;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Application\SportSessions;
use App\Shared\Application\StreakChests;
use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le streak de l'assiduité (#346) : le pointage du jour, et le coffre de la semaine passée.
 *
 * **Le jour vient de l'horloge serveur**, dans le fuseau du joueur : le client dit « je suis
 * là », jamais quel jour il est.
 *
 * **Le coffre tombe au premier pointage de la semaine suivante**, pas le dimanche : le
 * palier dépend des séances de toute la semaine, dimanche compris. Pas de job planifié — le
 * pointage suivant suffit, et sans lui, il n'y a personne à qui le montrer.
 *
 * ponytail: une séance du dimanche synchronisée *après* le pointage du lundi ne monte plus le
 * palier. Le client pointe après sa synchronisation ; regarder la semaine encore une fois au
 * pointage du mardi si ça arrive pour de vrai.
 */
final readonly class StreakTracker
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

    /** @return array{streak: StreakState, chest: ?DroppedItem} */
    public function checkIn(Uuid $player): array
    {
        $timezone = $this->timezones->of($player);
        $today = LocalDay::containing($this->clock->now(), $timezone)->date;

        return $this->manager->wrapInTransaction(function () use ($player, $timezone, $today): array {
            $this->manager->getConnection()->executeStatement(
                'INSERT INTO engagement_check_in (user_id, day) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$player->toRfc4122(), $today],
            );
            $checkIns = $this->checkIns($player);

            return ['streak' => $this->state($player, $timezone, $today, $checkIns), 'chest' => $this->grantLastWeek($player, $timezone, $today, $checkIns)];
        });
    }

    public function current(Uuid $player): StreakState
    {
        $timezone = $this->timezones->of($player);

        return $this->state($player, $timezone, LocalDay::containing($this->clock->now(), $timezone)->date, $this->checkIns($player));
    }

    /** @param array<string, true> $checkIns */
    private function state(Uuid $player, Timezone $timezone, string $today, array $checkIns): StreakState
    {
        $sportDays = $this->sportDays($player, $timezone, StreakRules::weekStart($today), self::next($today, 1));

        return new StreakState(StreakRules::days($checkIns, $today), isset($checkIns[$today]), $sportDays, StreakRules::rarityFor($sportDays));
    }

    /** @param array<string, true> $checkIns */
    private function grantLastWeek(Uuid $player, Timezone $timezone, string $today, array $checkIns): ?DroppedItem
    {
        $monday = self::next(StreakRules::weekStart($today), -StreakRules::WEEK);
        for ($offset = 0; $offset < StreakRules::WEEK; ++$offset) {
            if (!isset($checkIns[self::next($monday, $offset)])) {
                return null;
            }
        }

        $sportDays = $this->sportDays($player, $timezone, $monday, self::next($monday, StreakRules::WEEK));
        $rarity = StreakRules::rarityFor($sportDays);
        /** @var array{chests: array<string, string>} $settings */
        $settings = $this->rulesets->snapshot()['streak'] ?? throw new LogicException('Le réglage publié "streak" est absent. Appliquer les migrations.');
        $itemKey = $settings['chests'][$rarity] ?? throw new LogicException(\sprintf('Aucun coffre de streak publié pour la rareté %s.', $rarity));
        $now = $this->clock->now();

        // La contrainte d'unicité tranche entre deux pointages concurrents : un seul insère.
        $inserted = $this->manager->getConnection()->executeStatement(
            'INSERT INTO engagement_streak_chest (id, user_id, week_start, sport_days, rarity, item_key, ruleset_version, granted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [Uuid::v7()->toRfc4122(), $player->toRfc4122(), $monday, $sportDays, $rarity, $itemKey, $this->rulesets->version(), $now->format(\DATE_ATOM)],
        );

        return 1 === $inserted ? $this->chests->grant($player, $itemKey, $now) : null;
    }

    /** Jours locaux de `[$from, $until[` avec au moins une séance qui compte. */
    private function sportDays(Uuid $player, Timezone $timezone, string $from, string $until): int
    {
        $days = [];
        foreach ($this->sessions->startedBetween($player, self::midnight($from, $timezone), self::midnight($until, $timezone)) as $startedAt) {
            $days[LocalDay::containing($startedAt, $timezone)->date] = true;
        }

        return \count($days);
    }

    /**
     * ponytail: tout l'historique de pointage est relu — 365 lignes par an et par joueur.
     * Persister la série si le profil le demande un jour.
     *
     * @return array<string, true>
     */
    private function checkIns(Uuid $player): array
    {
        /** @var list<string> $days */
        $days = $this->manager->getConnection()->fetchFirstColumn('SELECT day FROM engagement_check_in WHERE user_id = ?', [$player->toRfc4122()]);

        return array_fill_keys($days, true);
    }

    private static function midnight(string $day, Timezone $timezone): DateTimeImmutable
    {
        return LocalDay::containing(new DateTimeImmutable($day.' 12:00', $timezone->toDateTimeZone()), $timezone)->startsAt;
    }

    private static function next(string $day, int $days): string
    {
        return new DateTimeImmutable($day)->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }
}
