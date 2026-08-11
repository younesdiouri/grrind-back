<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Ce qu'un profil dit des titres d'un joueur : celui qu'il porte, et celui qu'il vise.
 *
 * Les deux ensemble et non deux appels : ils se calculent du même relevé, et les séparer
 * ferait relire le ledger deux fois pour afficher un seul écran.
 */
final readonly class PlayerTitleStanding
{
    public function __construct(
        /** `null` si le joueur n'en a débloqué aucun, ou a choisi de n'en afficher aucun. */
        public ?PlayerTitle $active,
        /** Le plus proche d'aboutir parmi ceux qui restent. `null` quand il les a tous. */
        public ?PlayerTitle $next,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }
}
