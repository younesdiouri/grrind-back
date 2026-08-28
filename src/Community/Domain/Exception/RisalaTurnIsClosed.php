<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * On ne choisit plus : l'échéance est passée, ou le tour a déjà été scellé par la bascule
 * hebdomadaire.
 *
 * Une règle violée et non un conflit, malgré la ressemblance avec {@see GuildIsFull} : une
 * guilde pleine peut se vider, une échéance passée ne revient jamais. Le client ne doit pas
 * réessayer.
 *
 * L'échéance voyage dans le contexte parce que le client l'affiche — « tu avais jusqu'à
 * dimanche 20h » se lit, « choix refusé » se subit.
 */
final class RisalaTurnIsClosed extends RuleViolationError
{
    public function __construct(string $deadline)
    {
        parent::__construct(
            \sprintf('Le tour s\'est refermé le %s.', $deadline),
            ['deadline' => $deadline],
        );
    }

    public function type(): string
    {
        return 'risala-turn-is-closed';
    }
}
