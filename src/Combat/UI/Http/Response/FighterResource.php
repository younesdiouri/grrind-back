<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

/**
 * Un combattant tel que le client l'affiche — joueur ou ennemi, même forme, voir le
 * docblock de {@see \App\Combat\Domain\Fighter} pour pourquoi les deux entrent par la même
 * porte.
 *
 * **Le domaine porte des millièmes, le contrat des pourcentages.** `mitigationPercent` et
 * `extraTurnPercent` sont résolus ici, jamais recomposés côté client à partir de deux
 * taux — même règle que `bonusPercent` sur une Risāla. La conversion est une division
 * entière tronquée par 10, le même geste arithmétique que partout ailleurs sur une valeur
 * de jeu (voir {@see \App\Combat\Application\FighterFactory::scale()}) : jamais de flottant,
 * même pour un simple affichage.
 */
final readonly class FighterResource
{
    private function __construct(
        public int $hp,
        public int $damage,
        public int $mitigationPercent,
        public int $extraTurnPercent,
    ) {
    }

    /**
     * @param array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int} $fighter
     */
    public static function from(array $fighter): self
    {
        return new self(
            $fighter['hp'],
            $fighter['damage'],
            self::percentOf($fighter['mitigationPermille']),
            self::percentOf($fighter['extraTurnPermille']),
        );
    }

    /**
     * @return array{hp: int, damage: int, mitigationPercent: int, extraTurnPercent: int}
     */
    public function toArray(): array
    {
        return [
            'hp' => $this->hp,
            'damage' => $this->damage,
            'mitigationPercent' => $this->mitigationPercent,
            'extraTurnPercent' => $this->extraTurnPercent,
        ];
    }

    private static function percentOf(int $permille): int
    {
        return intdiv($permille, 10);
    }
}
