<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Le palier et les cinq caractéristiques d'un joueur, **lus de la même ligne, dans la même
 * requête** — voir {@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository::progressionsOf()}.
 *
 * Sur l'entité, {@see ProgressionSnapshot::standing()} et {@see ProgressionSnapshot::attributes()}
 * /{@see ProgressionSnapshot::vitality()} restent trois méthodes séparées, et pour une bonne
 * raison : elle encadre un `retotal()`, donc appeler les trois d'affilée pourrait mélanger un
 * instant d'avant et un instant d'après si un appelant s'y prenait mal.
 *
 * **Ici, c'est l'inverse qui menace.** Ce type ne sert qu'une lecture d'un joueur *par
 * quelqu'un d'autre* — aucun `retotal()` ne l'encadre, il n'y a pas de transaction à tenir.
 * Le risque n'est donc pas de lire un avant et un après du même appel : c'est qu'*une écriture
 * concurrente s'intercale entre deux requêtes distinctes* et fasse cohabiter, dans la même
 * réponse HTTP, un palier d'avant sa complétion avec des caractéristiques d'après. Deux
 * lectures à cet étage ne sont donc pas deux questions comme sur l'entité : c'est une seule
 * ligne, lue une seule fois, pour que les deux décrivent forcément le même instant.
 */
final readonly class PlayerStanding
{
    public function __construct(
        public LevelStanding $standing,
        public PlayerCharacteristics $characteristics,
    ) {
    }
}
