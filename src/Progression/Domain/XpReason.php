<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Ce qui a provoqué l'écriture au ledger.
 *
 * Deux valeurs, et pas de « correction manuelle » : aucune route ni commande n'en crédite,
 * et une valeur qu'aucun code n'écrit est une porte qu'on finit par pousser. Le jour où un
 * ajustement d'exploitation sera nécessaire, il aura son ticket et sa traçabilité propre.
 */
enum XpReason: string
{
    case SessionCompleted = 'SESSION_COMPLETED';

    /**
     * La contrepartie négative d'un crédit. On ne supprime jamais une transaction : la
     * séance invalidée reste au ledger, et son annulation aussi — c'est ce qui permet de
     * répondre « pourquoi ai-je perdu 90 XP ? » six mois plus tard.
     */
    case SessionInvalidated = 'SESSION_INVALIDATED';
}
