<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Un coup esquivé : `$attacker` a attaqué, la mobilité de sa cible l'a évité — aucun point
 * de vie n'est perdu, et rien d'autre à porter. Émis **à la place** de l'`Attack` que ce
 * tour aurait produit, jamais en plus d'elle : voir le docblock de {@see BattleSimulator}
 * pour le jet qui décide entre les deux (#218).
 *
 * Même geste qu'{@see Attack} : la cible n'est pas portée, elle se déduit de l'opposé
 * d'`$attacker` — {@see Actor::opponent()} — un combat n'en ayant jamais plus de deux.
 */
final readonly class Dodge implements BattleEvent
{
    public function __construct(
        public Actor $attacker,
    ) {
    }
}
