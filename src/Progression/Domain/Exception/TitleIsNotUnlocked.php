<?php

declare(strict_types=1);

namespace App\Progression\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le titre existe, le joueur ne l'a pas. Un refus de règle de jeu, pas un défaut
 * d'autorisation : la requête est légitime, c'est la condition qui n'est pas remplie.
 */
final class TitleIsNotUnlocked extends RuleViolationError
{
    public function __construct(string $titleId)
    {
        parent::__construct(\sprintf('Le titre "%s" n\'est pas débloqué.', $titleId), ['titleId' => $titleId]);
    }

    public function type(): string
    {
        return 'title-not-unlocked';
    }
}
