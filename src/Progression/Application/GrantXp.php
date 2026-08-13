<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Créditer un joueur pour un workout.
 *
 * Le montant n'est pas un paramètre — il se calcule, il ne se demande pas. Les
 * modificateurs actifs pas davantage : ils se résolvent, et sous le verrou. Un appelant qui
 * pourrait les fournir pourrait aussi les inventer, et une commande porte une intention,
 * jamais un état dérivé.
 *
 * **Le *quand*, lui, est bien un paramètre, et il l'est devenu au #89.** Le handler datait
 * sur l'horloge serveur tant que Grrind tenait le chronomètre : créditer et pratiquer se
 * passaient à la même seconde. L'import les sépare, et seul l'appelant sait quand le sport
 * a eu lieu — le déduire d'une horloge serveur entasserait dix jours de séances sur la
 * journée de la synchronisation.
 */
final readonly class GrantXp
{
    /**
     * @param Uuid              $sessionId       la source, qui rend l'écriture idempotente
     * @param int               $durationSeconds la durée **retenue**, déjà écrêtée par `Training`
     * @param DateTimeImmutable $occurredAt      l'instant du sport, qui range l'écriture dans une journée
     */
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
        public Discipline $discipline,
        public int $durationSeconds,
        public DateTimeImmutable $occurredAt,
        /**
         * Ce que la montre a mesuré, et qui entre dans le calcul (#90). Contrairement au
         * montant, ce n'est pas un état dérivé mais une **donnée du fait** : le module qui
         * crédite ne peut pas aller la chercher, elle appartient au workout.
         *
         * `null` est « non mesuré », jamais zéro.
         */
        public ?int $distanceMeters = null,
        public ?int $elevationGainMeters = null,
    ) {
    }
}
