<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\Exception\LedgerIsNotRewritable;
use App\Progression\Domain\XpTransaction;
use App\Progression\Domain\XpTransactionLine;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Refuse tout `UPDATE` et tout `DELETE` sur le ledger.
 *
 * Les entités n'ont déjà aucun mutateur, ce qui rend l'écriture difficile ; ce listener la
 * rend impossible par les chemins qui restent — un `remove()` en cascade, un champ modifié
 * par une désérialisation, un `clear()` mal placé. C'est le point d'extension prévu par
 * Doctrine, pas un contrôle inventé à côté.
 *
 * **Ce qu'il ne couvre pas**, et c'est assumé : un `DELETE` en SQL direct ou une requête
 * DQL de masse ne passent pas par l'unité de travail, donc pas par ici. La garantie est
 * applicative, par choix — un trigger PostgreSQL couvrirait ces cas et a été écarté.
 *
 * @see https://symfony.com/doc/current/doctrine/events.html
 */
#[AsEntityListener(event: Events::preUpdate, method: 'refuseUpdate', entity: XpTransaction::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'refuseUpdate', entity: XpTransactionLine::class)]
#[AsEntityListener(event: Events::preRemove, method: 'refuseRemoval', entity: XpTransaction::class)]
#[AsEntityListener(event: Events::preRemove, method: 'refuseRemoval', entity: XpTransactionLine::class)]
final readonly class LedgerIsAppendOnly
{
    public function refuseUpdate(object $entity): never
    {
        throw new LedgerIsNotRewritable('UPDATE', $entity::class);
    }

    public function refuseRemoval(object $entity): never
    {
        throw new LedgerIsNotRewritable('DELETE', $entity::class);
    }
}
