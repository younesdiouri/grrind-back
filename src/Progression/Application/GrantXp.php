<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Uid\Uuid;

/**
 * Créditer un joueur pour une séance close.
 *
 * Le *quand* n'est pas un paramètre : c'est le handler qui date, sur l'horloge serveur.
 * Le montant non plus — il se calcule, il ne se demande pas. Les modificateurs actifs pas
 * davantage : ils se résolvent, et sous le verrou. Un appelant qui pourrait les fournir
 * pourrait aussi les inventer, et une commande porte une intention, jamais un état dérivé.
 */
final readonly class GrantXp
{
    /**
     * @param Uuid $sessionId       la source, qui rend l'écriture idempotente
     * @param int  $durationSeconds la durée **retenue**, déjà écrêtée par `Training`
     */
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
        public Discipline $discipline,
        public int $durationSeconds,
    ) {
    }
}
