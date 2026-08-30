<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le joueur demande à équiper un objet qu'il ne possède pas — soit qu'aucune ligne
 * d'inventaire n'existe pour cette clé, soit qu'elle existe avec une quantité nulle (#29).
 *
 * Sert aussi pour une clé qui ne désigne aucun objet du catalogue : un objet inconnu ne peut
 * par construction être possédé par personne, et une clé absente n'a pas à distinguer les
 * deux cas devant le joueur — même raisonnement qu'{@see \App\Combat\Domain\Exception\EnemyKeyUnknown}
 * pour une famille différente de raison.
 *
 * Une règle de jeu, pas un problème d'autorisation : l'objet n'existe pas *pour ce joueur*,
 * ce n'est pas une ressource qu'on lui cache, d'où le 422 plutôt qu'un 404 ou un 403.
 */
final class ItemNotOwned extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct(
            \sprintf('"%s" n\'est pas possédé par ce joueur.', $itemKey),
            ['itemKey' => $itemKey],
        );
    }

    public function type(): string
    {
        return 'item-not-owned';
    }
}
