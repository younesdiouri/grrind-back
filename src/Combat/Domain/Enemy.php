<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Un ennemi du catalogue PvE, tel qu'il est écrit dans `config/game/v1/combat.yaml`.
 *
 * Un objet de domaine pur, jamais persisté : ce qui sera stocké au #211, c'est le combat
 * qui l'a opposé à un joueur, pas l'ennemi lui-même. `key` identifie l'entrée dans le
 * catalogue et sa traduction ({@see \App\Combat\Infrastructure\Translation\EnemyTranslator}) ;
 * `level` n'est jamais une caractéristique de combat, mais sa lecture dépend de la liste de
 * {@see EnemyCatalog} qui porte l'entrée (#219) : le palier auquel `forLevel()` le choisirait
 * tout seul pour un ennemi de `enemies:`, le niveau minimum requis pour l'affronter pour un
 * boss de `bosses:`. Une seule classe sert les deux, voir le docblock d'`EnemyCatalog`.
 *
 * Les stats de combat (`hp`, `damage`, `mitigationPermille`, `extraTurnPermille`,
 * `dodgePermille`) sont écrites en clair par palier plutôt que dérivées d'une formule : voir
 * le docblock de `EnemyCatalog` pour pourquoi.
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
        public int $dodgePermille,
    ) {
    }
}
