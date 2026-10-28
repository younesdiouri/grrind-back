<?php

declare(strict_types=1);

namespace App\Training\Domain\Together;

/** Le framework ne fournit pas de règle de coprésence ; calcul pur, sans coordonnées persistées dans le verdict. */
final readonly class TogetherDetector
{
    public function __construct(private TogetherRules $rules)
    {
    }

    /**
     * Cherche un groupe réellement commun, plafonné à quatre (le contrat affiche alors « 4+ »).
     * Les candidats sont triés pour rendre le choix stable, sans fusion transitive de groupes.
     *
     * @param list<TogetherTrack> $tracks
     *
     * @return non-empty-list<TogetherTrack>
     */
    public function groupFor(TogetherTrack $anchor, array $tracks): array
    {
        if (1 === $this->rules->get('maximum_players')) {
            return [$anchor];
        }
        $candidates = array_values(array_filter($tracks, fn (TogetherTrack $other): bool => $other->user !== $anchor->user && $other->sport === $anchor->sport && null !== $this->proof([$anchor, $other])));
        usort($candidates, static fn (TogetherTrack $a, TogetherTrack $b): int => $a->id <=> $b->id);

        return $this->extend([$anchor], $candidates);
    }

    /** @param non-empty-list<TogetherTrack> $group
     * @return array{startedAt: int, endedAt: int, overlapPercent: int, coveragePercent: int}|null
     */
    public function proof(array $group): ?array
    {
        $start = max(array_map(static fn (TogetherTrack $t): int => $t->start, $group));
        $end = min(array_map(static fn (TogetherTrack $t): int => $t->end, $group));
        $overlap = $end - $start;
        $minimum = 100;
        foreach ($group as $track) {
            if (\count($track->points) < 2) {
                return null;
            }
            $duration = $track->end - $track->start;
            if ($duration <= 0 || $overlap * 100 < $duration * $this->rules->get('overlap_percent')) {
                return null;
            }
            $minimum = min($minimum, intdiv($overlap * 100, $duration));
        }
        $covered = 0;
        $indices = array_fill(0, \count($group), 0);
        $step = $this->rules->get('sample_seconds');
        for ($at = $start; $at < $end; $at += $step) {
            $positions = [];
            foreach ($group as $i => $track) {
                $last = \count($track->points) - 1;
                while ($indices[$i] < $last && abs($track->points[$indices[$i] + 1]['at'] - $at) <= abs($track->points[$indices[$i]]['at'] - $at)) {
                    ++$indices[$i];
                }
                $point = $track->points[$indices[$i]] ?? null;
                if (null === $point || abs($point['at'] - $at) * 2 > $this->rules->get('maximum_gap_seconds') || $point['accuracy'] > $this->rules->get('maximum_accuracy_meters')) {
                    continue 2;
                }
                foreach ($positions as $position) {
                    if (self::distance($point, $position) > $this->rules->get('distance_meters')) {
                        continue 3;
                    }
                }
                $positions[] = $point;
            }
            $covered += min($step, $end - $at);
        }
        if ($covered * 100 < $overlap * $this->rules->get('coverage_percent')) {
            return null;
        }

        return ['startedAt' => $start, 'endedAt' => $end, 'overlapPercent' => $minimum, 'coveragePercent' => intdiv($covered * 100, $overlap)];
    }

    /** @param non-empty-list<TogetherTrack> $group
     * @param list<TogetherTrack> $candidates
     *
     * @return non-empty-list<TogetherTrack>
     */
    private function extend(array $group, array $candidates): array
    {
        $best = $group;
        foreach ($candidates as $i => $candidate) {
            if (\in_array($candidate->user, array_map(static fn (TogetherTrack $t): string => $t->user, $group), true)) {
                continue;
            }
            $next = [...$group, $candidate];
            if (null === $this->proof($next)) {
                continue;
            }
            if (\count($next) === $this->rules->get('maximum_players')) {
                return $next;
            }
            $found = $this->extend($next, \array_slice($candidates, $i + 1));
            if (\count($found) > \count($best)) {
                $best = $found;
            }
            if (\count($best) === $this->rules->get('maximum_players')) {
                break;
            }
        }

        return $best;
    }

    /** @param array{latitude: float, longitude: float} $a
     * @param array{latitude: float, longitude: float} $b
     */
    private static function distance(array $a, array $b): float
    {
        $lat = deg2rad($b['latitude'] - $a['latitude']);
        $lon = deg2rad($b['longitude'] - $a['longitude']);
        $h = sin($lat / 2) ** 2 + cos(deg2rad($a['latitude'])) * cos(deg2rad($b['latitude'])) * sin($lon / 2) ** 2;

        return 12742000 * asin(sqrt(min(1.0, $h)));
    }
}
