<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

/**
 * Un combattant tel que le client l'affiche — joueur ou ennemi, même forme, voir le
 * docblock de {@see \App\Combat\Domain\Fighter} pour pourquoi les deux entrent par la même
 * porte.
 *
 * **Le domaine porte des millièmes, le contrat des pourcentages.** `mitigationPercent`,
 * `comboPercent` et `dodgePercent` sont résolus ici, jamais recomposés côté client à
 * partir de deux taux — même règle que `bonusPercent` sur une Risāla. La conversion est une
 * division entière tronquée par 10, le même geste arithmétique que partout ailleurs sur une
 * valeur de jeu (voir {@see \App\Combat\Domain\CombatMath::scale()}) : jamais de
 * flottant, même pour un simple affichage.
 * Précision et résistance critique réduisent relativement le taux opposé :
 * esquive 30 % contre précision 20 % donne 24 %, pas 10 %. Maintenance ralentit
 * la fatigue, elle n'ajoute jamais de dégâts au premier coup.
 */
final readonly class FighterResource
{
    private function __construct(
        public int $hp,
        public int $damage,
        public int $mitigationPercent,
        public int $comboPercent,
        public int $dodgePercent,
        public int $maintenancePercent,
        public int $criticalChancePercent,
        public int $guardPercent,
        public int $criticalResistancePercent,
        public int $cooldownReductionPercent,
        public int $precisionPercent,
    ) {
    }

    /**
     * @param array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int} $fighter
     */
    public static function from(array $fighter): self
    {
        return new self(
            $fighter['hp'],
            $fighter['damage'],
            self::percentOf($fighter['mitigationPermille']),
            self::percentOf($fighter['comboPermille']),
            self::percentOf($fighter['dodgePermille']),
            self::percentOf($fighter['maintenancePermille']),
            self::percentOf($fighter['criticalChancePermille']),
            self::percentOf($fighter['guardPermille']),
            self::percentOf($fighter['criticalResistancePermille']),
            self::percentOf($fighter['cooldownReductionPermille']),
            self::percentOf($fighter['precisionPermille']),
        );
    }

    /**
     * @return array{hp: int, damage: int, mitigationPercent: int, comboPercent: int, dodgePercent: int, maintenancePercent: int, criticalChancePercent: int, guardPercent: int, criticalResistancePercent: int, cooldownReductionPercent: int, precisionPercent: int}
     */
    public function toArray(): array
    {
        return [
            'hp' => $this->hp,
            'damage' => $this->damage,
            'mitigationPercent' => $this->mitigationPercent,
            'comboPercent' => $this->comboPercent,
            'dodgePercent' => $this->dodgePercent,
            'maintenancePercent' => $this->maintenancePercent,
            'criticalChancePercent' => $this->criticalChancePercent,
            'guardPercent' => $this->guardPercent,
            'criticalResistancePercent' => $this->criticalResistancePercent,
            'cooldownReductionPercent' => $this->cooldownReductionPercent,
            'precisionPercent' => $this->precisionPercent,
        ];
    }

    private static function percentOf(int $permille): int
    {
        return intdiv($permille, 10);
    }
}
