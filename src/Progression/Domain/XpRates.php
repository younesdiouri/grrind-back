<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Le barème de base : ce qu'une heure de chaque discipline vaut, avant tout modificateur.
 *
 * Objet typé et immuable, construit une fois à la compilation du conteneur depuis
 * `config/game/v1/xp.yaml`. C'est lui qui porte les règles de cohérence du barème, et
 * `XpSection` les lui fait dire plutôt que de les réécrire.
 *
 * **Arithmétique entière de bout en bout.** `intdiv(durée × taux, 3600)` tronque vers le
 * bas : une séance ne rapporte jamais plus que ce que le barème annonce, et aucun arrondi
 * flottant ne se glisse dans une valeur qui finira au ledger.
 */
final readonly class XpRates
{
    /** @var array<string, int> valeur de discipline → XP par heure */
    private array $perHour;

    /**
     * @param list<array{discipline: string, xp_per_hour: int}> $disciplines
     */
    public function __construct(array $disciplines)
    {
        $perHour = [];

        foreach ($disciplines as $rate) {
            $discipline = Discipline::tryFrom($rate['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue au barème d\'XP : "%s".', $rate['discipline']));

            if (isset($perHour[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Discipline en double au barème d\'XP : "%s".', $discipline->value));
            }

            if ($rate['xp_per_hour'] < 1) {
                throw new InvalidArgumentException(\sprintf('Une heure de "%s" doit valoir au moins 1 XP.', $discipline->value));
            }

            $perHour[$discipline->value] = $rate['xp_per_hour'];
        }

        // Une discipline sans barème rapporterait zéro en silence — un joueur découvrirait
        // le trou, pas nous. On préfère ne pas démarrer.
        foreach (Discipline::cases() as $discipline) {
            if (!isset($perHour[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Aucun barème d\'XP pour la discipline "%s".', $discipline->value));
            }
        }

        $this->perHour = $perHour;
    }

    public function perHourOf(Discipline $discipline): int
    {
        return $this->perHour[$discipline->value];
    }

    /**
     * Le socle d'une séance, au prorata de sa durée retenue.
     *
     * @throws InvalidArgumentException une durée négative n'est pas une séance courte, c'est un bug d'appelant
     */
    public function baseFor(Discipline $discipline, int $durationSeconds): int
    {
        if ($durationSeconds < 0) {
            throw new InvalidArgumentException(\sprintf('Une séance ne dure pas %d secondes.', $durationSeconds));
        }

        return intdiv($durationSeconds * $this->perHourOf($discipline), 3600);
    }
}
