<?php

declare(strict_types=1);

namespace App\Combat\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * La clé du corps de `POST /api/battles` ne désigne ni un ennemi ordinaire ni un boss du
 * catalogue (#219).
 *
 * **422 et non 404.** Le catalogue est public par `GET /api/enemies` — il n'y a rien à
 * cacher derrière un 404, contrairement à une ressource qui appartient à un compte. Le
 * refus est une règle de jeu (« ce que tu demandes n'existe pas »), pas un problème
 * d'autorisation.
 */
final class EnemyKeyUnknown extends RuleViolationError
{
    public function __construct(string $key)
    {
        parent::__construct(
            \sprintf('"%s" ne désigne aucun adversaire du catalogue.', $key),
            ['key' => $key],
        );
    }

    public function type(): string
    {
        return 'enemy-key-unknown';
    }
}
