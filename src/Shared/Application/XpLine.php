<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Une ligne du détail d'un gain d'XP, telle qu'un autre module la reçoit : d'où elle vient,
 * ce qu'elle pèse.
 *
 * **L'ordre de la liste qui la porte est le contrat** — c'est celui de l'animation, pas un
 * tri que le client refait. Voir {@see SessionReward}.
 *
 * `source` est une chaîne et non un enum parce que le vocabulaire appartient à
 * `Progression` : c'est une valeur de `App\Progression\Domain\XpBreakdownSource`, que
 * `Shared` n'a pas à connaître pour la transporter. Même geste que `unit` dans
 * {@see PlayerTitle}.
 */
final readonly class XpLine
{
    public function __construct(
        public string $source,
        public int $amount,
    ) {
    }
}
