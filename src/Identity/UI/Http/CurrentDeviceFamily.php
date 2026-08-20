<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Sur un argument `?Uuid` de contrôleur : la famille de refresh tokens dont est né le jeton
 * d'accès courant, lue dans son claim `fid` (#136, arbitrage B — voir le docblock de
 * {@see \App\Identity\Domain\UserDevice}).
 *
 * `null` et jamais une erreur si le claim est absent : un jeton signé juste avant le
 * déploiement de ce claim reste valable jusqu'à quinze minutes après, et une route qui
 * refuserait ces requêtes romprait des sessions qui n'ont rien fait de mal.
 *
 * @see CurrentDeviceFamilyResolver
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CurrentDeviceFamily extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(CurrentDeviceFamilyResolver::class);
    }
}
