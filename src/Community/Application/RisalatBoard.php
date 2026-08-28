<?php

declare(strict_types=1);

namespace App\Community\Application;

use DateTimeImmutable;

/**
 * L'écran des Risālāt d'un joueur, d'un bloc : ce qui est vivant, et le tour en cours.
 *
 * Les deux ensemble parce que l'onglet ne s'ouvre jamais sans les deux — même raison que
 * `GuildDetail` au #117 : les séparer coûterait un aller-retour pour un écran qui n'a rien à
 * afficher entre-temps.
 *
 * **`$nextRevealAt` en dernier**, après `$live` et `$turn` : l'ordre de ces propriétés est
 * celui de l'écran — ce qui court maintenant, ce qui se prépare, puis le rendez-vous qui fait
 * basculer les deux (#202).
 */
final readonly class RisalatBoard
{
    public function __construct(
        /** @var list<RisalaView> dans l'ordre de révélation, qui est aussi celui de leur extinction */
        public array $live,
        /** `null` quand la guilde n'a pas encore de tour — un seul membre, ou fondée depuis la dernière bascule. */
        public ?RisalaTurnView $turn,
        /**
         * Le prochain rendez-vous hebdomadaire, toujours présent — même sans `$live` ni `$turn`,
         * c'est justement là que le client n'a rien d'autre à dire (#202).
         */
        public DateTimeImmutable $nextRevealAt,
    ) {
    }
}
