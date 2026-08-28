<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use DomainException;

/**
 * On ne choisit plus : l'échéance est passée, ou le tour a déjà été scellé par la bascule
 * hebdomadaire.
 *
 * La date est dans le message parce que le client l'affiche — « tu avais jusqu'à dimanche
 * 20h » se lit, « choix refusé » se subit.
 */
final class RisalaTurnIsClosed extends DomainException
{
}
