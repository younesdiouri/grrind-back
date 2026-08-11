<?php

declare(strict_types=1);

namespace App\Training\Domain;

use InvalidArgumentException;

/**
 * Les seuils qui encadrent une séance. Ils vivent dans le domaine et non dans une
 * classe de configuration : l'agrégat les reçoit et décide lui-même, sans que personne
 * ait à se souvenir de vérifier avant de l'appeler.
 *
 * Valeurs et justification de chaque seuil : `config/game/v1/training.yaml`.
 */
final readonly class TrainingRules
{
    public function __construct(
        public int $minimumDurationSeconds,
        public int $maximumDurationSeconds,
        public int $cooldownSeconds,
    ) {
        // Une configuration incohérente tombe au démarrage, pas le jour où un joueur
        // croise le cas.
        if ($minimumDurationSeconds < 0 || $cooldownSeconds < 0) {
            throw new InvalidArgumentException('Un seuil de durée ne peut pas être négatif.');
        }

        if ($maximumDurationSeconds < $minimumDurationSeconds) {
            throw new InvalidArgumentException('Le plafond de durée est sous le plancher : aucune séance ne pourrait être close.');
        }
    }
}
