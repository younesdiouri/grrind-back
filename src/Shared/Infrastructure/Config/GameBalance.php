<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

/**
 * L'équilibrage du jeu, lu et validé, tel qu'il sera figé dans le conteneur compilé.
 *
 * C'est un objet de la compilation, pas de l'exécution : le code applicatif ne le voit
 * jamais. Il reçoit des objets typés — `WorkoutRules` et ses semblables — construits
 * depuis les paramètres que {@see GameBalancePass} en tire. Aucun tableau d'équilibrage
 * ne se promène dans le domaine.
 */
final readonly class GameBalance
{
    /**
     * @param array<string, array<string, mixed>> $sections nom de section → configuration normalisée
     * @param string                              $version  le `rulesetVersion` stocké avec chaque transaction d'XP
     */
    public function __construct(
        public array $sections,
        public string $version,
    ) {
    }
}
