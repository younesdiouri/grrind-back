<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Sur un argument `Guild` de contrôleur : charge la guilde du `{id}` de la route, et rend
 * **404 si l'appelant n'a rien à y voir** — qu'elle n'existe pas ou qu'il n'en soit pas
 * membre, indistinctement.
 *
 * C'est un alias typé de `#[ValueResolver]`, pour deux raisons. La première est qu'il se
 * lit : `#[VisibleGuild] Guild $guild` dit ce qui est garanti. La seconde est qu'il **cible**
 * le resolver, donc aucun autre — celui de Doctrine en particulier — ne peut servir la
 * guilde en passant à côté du contrôle.
 *
 * @see VisibleGuildResolver pour le pourquoi du 404
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class VisibleGuild extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(VisibleGuildResolver::class);
    }
}
