<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Un ennemi du catalogue PvE, tel qu'il est écrit dans `config/game/v1/combat.yaml`.
 *
 * Un objet de domaine pur, jamais persisté : ce qui sera stocké au #211, c'est le combat
 * qui l'a opposé à un joueur, pas l'ennemi lui-même. `key` identifie l'entrée dans le
 * catalogue et sa traduction ({@see \App\Combat\Infrastructure\Translation\EnemyTranslator}) ;
 * `level` est le niveau de joueur auquel il est opposé, pas une caractéristique de combat.
 *
 * Les stats de combat (`hp`, `damage`, `mitigationPermille`, `extraTurnPermille`) sont
 * écrites en clair par palier plutôt que dérivées d'une formule : voir le docblock de
 * `EnemyCatalog` pour pourquoi.
 */
final readonly class Enemy
{
    public function __construct(
        public string $key,
        public int $level,
        public int $hp,
        public int $damage,
        public int $mitigationPermille,
        public int $extraTurnPermille,
    ) {
    }
}
