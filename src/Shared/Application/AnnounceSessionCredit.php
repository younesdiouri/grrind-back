<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Contrat tombstone #255 : les messages #252 déjà sérialisés dans le transport Doctrine
 * restent désérialisables et routés jusqu'au drainage de `outbox` et `failed`, conformément
 * au versioning Symfony Messenger. {@see AnnounceSessionCreditHandler} ne les envoie plus :
 * il referme seulement la fenêtre historique adressée par ce `windowId`.
 */
final readonly class AnnounceSessionCredit
{
    public function __construct(public Uuid $playerId, public Uuid $windowId)
    {
    }
}
