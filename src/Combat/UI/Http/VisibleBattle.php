<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Sur un argument `Battle` de contrôleur : charge le combat du `{id}` de la route, et rend
 * **404 si l'appelant ne l'a pas mené** — inconnu ou étranger, indistinctement.
 *
 * Le pendant exact de {@see \App\Community\UI\Http\VisibleGuild} : il se lit — `#[VisibleBattle]
 * Battle $battle` dit ce qui est garanti — et il **cible** le resolver, donc aucun autre
 * (celui de Doctrine en particulier) ne peut servir le combat en passant à côté du contrôle.
 *
 * @see VisibleBattleResolver pour le pourquoi du 404
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class VisibleBattle extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(VisibleBattleResolver::class);
    }
}
