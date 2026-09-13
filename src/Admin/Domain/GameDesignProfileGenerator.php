<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Échantillonnage d'exploration, pas modèle de population : coupes uniformes du budget,
 * avec un quart de spécialistes (70–95 % sur un attribut tournant) pour éprouver les extrêmes.
 * Les quatre parts entières conservent exactement le budget, sans bonus ni équipement.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 */
final class GameDesignProfileGenerator
{
    /** @return list<ProfileInput> */
    public function generate(int $count, int $total, int $seed): array
    {
        if ($count < 1 || $count > 50 || $total < 1 || $total > 100000000 || $seed < 0 || $seed > 2000000000) {
            throw new InvalidArgumentException('Génération : 1–50 profils, budget 1–100000000 et graine 0–2000000000.');
        }
        $random = new Randomizer(new Mt19937($seed));
        $profiles = [];
        for ($i = 0; $i < $count; ++$i) {
            $specialist = 0 === $i % 4;
            if ($specialist) {
                $major = intdiv($total * $random->getInt(70, 95), 100);
                $parts = $this->partition($total - $major, 3, $random);
                array_splice($parts, intdiv($i, 4) % 4, 0, [$major]);
            } else {
                $parts = $this->partition($total, 4, $random);
            }
            $profiles[] = ['name' => \sprintf('Profil %02d%s', $i + 1, $specialist ? ' · spécialiste' : ''), 'strength' => $parts[0], 'endurance' => $parts[1], 'mobility' => $parts[2], 'dexterity' => $parts[3], 'vitalityOverride' => null, 'equipment' => []];
        }

        return $profiles;
    }

    /** @return list<int> */
    private function partition(int $total, int $count, Randomizer $random): array
    {
        $cuts = [0, $total];
        for ($i = 1; $i < $count; ++$i) {
            $cuts[] = $random->getInt(0, $total);
        }
        sort($cuts);
        $parts = [];
        for ($i = 1; $i <= $count; ++$i) {
            $parts[] = $cuts[$i] - $cuts[$i - 1];
        }

        return $parts;
    }
}
