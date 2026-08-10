<?php

declare(strict_types=1);

namespace App\Training\Domain;

use InvalidArgumentException;

/**
 * Les seuils qui encadrent une séance : durée plancher, durée plafond, cooldown.
 *
 * Ce sont des règles de game design autant que des règles anti-triche, et c'est
 * pourquoi elles vivent dans le domaine plutôt que dans une classe de configuration :
 * l'agrégat les reçoit et décide lui-même, sans que personne ait à se souvenir de
 * vérifier avant de l'appeler.
 *
 * Les valeurs viennent de `config/game/v1/training.yaml` — jamais de constantes en dur,
 * l'équilibrage d'un jeu se relit sans ouvrir de PHP. Voir ce fichier pour le pourquoi
 * de chaque seuil.
 */
final readonly class TrainingRules
{
    public function __construct(
        public int $minimumDurationSeconds,
        public int $maximumDurationSeconds,
        public int $cooldownSeconds,
    ) {
        // Une configuration incohérente doit tomber au démarrage et non le jour où un
        // joueur croise le cas : un plancher au-dessus du plafond rendrait toute séance
        // impossible à clore, et personne ne le verrait avant la production.
        if ($minimumDurationSeconds < 0 || $cooldownSeconds < 0) {
            throw new InvalidArgumentException('Un seuil de durée ne peut pas être négatif.');
        }

        if ($maximumDurationSeconds < $minimumDurationSeconds) {
            throw new InvalidArgumentException('Le plafond de durée est sous le plancher : aucune séance ne pourrait être close.');
        }
    }
}
