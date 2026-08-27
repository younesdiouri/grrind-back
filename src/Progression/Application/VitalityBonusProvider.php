<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Shared\Application\ActiveEnergyWindows;
use App\Shared\Domain\Activity\Vitality;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Bonifie la Vitality lue d'un snapshot avec l'énergie active de la fenêtre — le seul
 * endroit qui appelle {@see ActiveEnergyWindows} et {@see Vitality::bonused()} ensemble,
 * pour que `ProgressionStateProvider` (un joueur) et `TranslatedPlayerProgressions`
 * (plusieurs) ne dupliquent pas le même geste (#165).
 *
 * **Toute l'impureté du bonus tient ici**, comme `DailyLoadProvider` la tient pour le
 * calcul d'XP : `Vitality` reste une fonction pure, et ce qui interroge la base — ou choisit
 * `$endingOn` — est isolé dans cette classe, remplaçable dans un test sans rien simuler du
 * calcul lui-même.
 *
 * **`$endingOn` est un paramètre, jamais lu d'une horloge ici.** C'est l'appelant qui sait
 * dans quel fuseau situer « aujourd'hui » pour qui il sert — le fuseau exact du joueur pour
 * une lecture individuelle, une date de référence commune pour un lot de plusieurs joueurs
 * potentiellement dans des fuseaux différents. Voir le docblock de
 * `TranslatedPlayerProgressions` pour le compromis retenu sur ce second cas.
 */
final readonly class VitalityBonusProvider
{
    public function __construct(
        private ActiveEnergyWindows $activeEnergy,
        private Vitality $vitality,
        private int $windowDays,
    ) {
    }

    /**
     * @param array<string, int> $baseVitalityByUserId la Vitality *non* bonifiée de chaque
     *                                                 joueur — celle lue du snapshot, voir
     *                                                 le docblock de `ProgressionSnapshot`
     * @param list<Uuid>         $userIds              les mêmes joueurs, en liste, pour l'appel batch au port
     *
     * @return array<string, BonusedVitality> indexé par UUID en RFC 4122, une entrée par `$userIds`
     */
    public function of(array $baseVitalityByUserId, array $userIds, DateTimeImmutable $endingOn): array
    {
        $averages = $this->activeEnergy->averagesOf($userIds, $endingOn, $this->windowDays);

        $bonused = [];

        foreach ($userIds as $userId) {
            $key = $userId->toRfc4122();
            $base = $baseVitalityByUserId[$key] ?? 0;
            $average = $averages[$key] ?? 0;

            $bonused[$key] = new BonusedVitality(
                $this->vitality->bonused($base, $average),
                $this->vitality->explain($average),
            );
        }

        return $bonused;
    }
}
