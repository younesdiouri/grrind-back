<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\Discipline;

/**
 * Un seul champ : pas de `startedAt`, pas de `source`, pas de `userId`. Ce que le
 * client n'envoie pas, il ne peut pas mentir dessus.
 *
 * Le typage par l'enum remplace un `#[Assert\Choice]` qui doublerait sa liste : le
 * Serializer refuse une valeur inconnue, et `#[MapRequestPayload]` en fait un 422
 * nommant le champ fautif.
 */
final readonly class StartSessionRequest
{
    public function __construct(public Discipline $discipline)
    {
    }
}
