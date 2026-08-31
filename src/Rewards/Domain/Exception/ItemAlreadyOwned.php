<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le joueur possède déjà `$itemKey` (#229). Sans revente et sans consommable, un second
 * exemplaire d'un équipement n'achète strictement rien — un seul emplacement le porte,
 * `quantity` ne vaut rien de plus pour un objet dans le sac. Le refus est donc général et ne
 * dépend d'aucun drapeau du catalogue, contrairement à {@see ItemNotPurchasable}.
 *
 * **Un coffre y échappe (#230)** — voir le docblock d'{@see \App\Rewards\Application\Inventory::purchase()} :
 * il ne s'équipe jamais, un second exemplaire est une vraie ouverture de plus, pas du vide
 * facturé. Ce n'est donc plus « n'importe quel objet », mais tout `EQUIPMENT`.
 */
final class ItemAlreadyOwned extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct(
            \sprintf('"%s" est déjà possédé : sans revente ni consommable, un second exemplaire n\'achèterait rien.', $itemKey),
            ['itemKey' => $itemKey],
        );
    }

    public function type(): string
    {
        return 'item-already-owned';
    }
}
