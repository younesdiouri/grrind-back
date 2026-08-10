<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\Discipline;

/**
 * Un seul champ, et c'est le sujet du ticket : pas de `startedAt`, pas de `source`,
 * pas de `userId`. Ce que le client n'envoie pas, il ne peut pas mentir dessus.
 *
 * La discipline est typée par l'enum plutôt que validée par un `#[Assert\Choice]`
 * doublant sa liste : le Serializer refuse une valeur inconnue, et
 * `#[MapRequestPayload]` transforme cet échec de dénormalisation en violation, donc
 * en 422 avec le champ fautif — le même contrat d'erreur que le reste de l'API.
 * Un `null` ou un champ absent échouent de la même façon, la propriété n'étant pas
 * nullable ; `#[Assert\NotNull]` n'ajouterait rien.
 *
 * @see https://symfony.com/doc/current/controller.html#mapping-the-whole-request-payload
 */
final readonly class StartSessionRequest
{
    public function __construct(public Discipline $discipline)
    {
    }
}
