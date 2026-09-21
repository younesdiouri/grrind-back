<?php

declare(strict_types=1);

namespace App\Combat\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Se défier soi-même (#283).
 *
 * **422 et non 404**, à la différence de {@see OpponentNotFound} : l'UUID est celui de
 * l'appelant, il n'y a rien à cacher sur son existence. Le refus est une règle de jeu — un
 * combat oppose deux combattants — et le laisser passer produirait une ligne où le vainqueur
 * et le perdant sont le même joueur, que l'historique n'aurait aucun moyen d'afficher.
 */
final class CannotChallengeYourself extends RuleViolationError
{
    public function __construct()
    {
        parent::__construct('On ne se défie pas soi-même.');
    }

    public function type(): string
    {
        return 'cannot-challenge-yourself';
    }
}
