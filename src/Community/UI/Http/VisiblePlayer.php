<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Sur un argument `Uuid` de contrôleur : lit le `{id}` de la route et rend **404 si
 * l'appelant n'a rien à voir avec ce joueur** — inconnu ou étranger, indistinctement.
 *
 * Le pendant exact de {@see VisibleGuild}, et pour la même raison : le 404 devient le
 * comportement par défaut au lieu d'être quelque chose qu'un contrôleur doit penser à
 * écrire.
 *
 * @see VisiblePlayerResolver
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class VisiblePlayer extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(VisiblePlayerResolver::class);
    }
}
