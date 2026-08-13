<?php

declare(strict_types=1);

namespace App\Training\Domain;

use InvalidArgumentException;

/**
 * Les seuils qui encadrent un workout : sous le plancher ce n'est pas une séance, au
 * dessus du plafond c'est un arrêt oublié.
 *
 * Ils ne sont plus appliqués par l'agrégat depuis que le chronomètre a disparu (#85) :
 * un workout importé est un fait, et refuser un fait n'a pas de sens. Ce sont désormais
 * des règles d'**import** — ce qui entre, et ce que ça vaut — et c'est #91 qui les
 * applique, parce qu'elles s'y mêlent aux chevauchements et à la fenêtre d'antériorité.
 *
 * Le cooldown, lui, est parti pour de bon : Apple produit trois workouts d'affilée sans
 * demander la permission à personne, et refuser le troisième reviendrait à refuser un
 * fait qui a eu lieu.
 *
 * Valeurs et justification de chaque seuil : `config/game/v1/training.yaml`.
 */
final readonly class WorkoutRules
{
    public function __construct(
        public int $minimumDurationSeconds,
        public int $maximumDurationSeconds,
    ) {
        // Une configuration incohérente tombe au démarrage, pas le jour où un joueur
        // croise le cas.
        if ($minimumDurationSeconds < 0) {
            throw new InvalidArgumentException('Un seuil de durée ne peut pas être négatif.');
        }

        if ($maximumDurationSeconds < $minimumDurationSeconds) {
            throw new InvalidArgumentException('Le plafond de durée est sous le plancher : aucun workout ne pourrait être retenu.');
        }
    }
}
