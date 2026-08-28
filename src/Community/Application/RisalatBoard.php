<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * L'écran des Risālāt d'un joueur, d'un bloc : ce qui est vivant, et le tour en cours.
 *
 * Les deux ensemble parce que l'onglet ne s'ouvre jamais sans les deux — même raison que
 * `GuildDetail` au #117 : les séparer coûterait un aller-retour pour un écran qui n'a rien à
 * afficher entre-temps.
 */
final readonly class RisalatBoard
{
    public function __construct(
        /** @var list<RisalaView> dans l'ordre de révélation, qui est aussi celui de leur extinction */
        public array $live,
        /** `null` quand la guilde n'a pas encore de tour — un seul membre, ou fondée depuis la dernière bascule. */
        public ?RisalaTurnView $turn,
    ) {
    }
}
