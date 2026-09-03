<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * La langue d'un joueur est un attribut d'Identity, mais les notifications sont produites par
 * d'autres modules et doivent être traduites pour leur destinataire, pas leur déclencheur.
 */
interface PlayerLocales
{
    /** Retourne l'anglais pour un compte inconnu : un échec de traduction ne doit pas perdre une notification. */
    public function localeOf(Uuid $userId): string;
}
