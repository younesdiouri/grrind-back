<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\AttributeGains;

/**
 * Les quatre caractéristiques et la Vitality d'un joueur, lues **ensemble** d'un snapshot —
 * le pendant à plusieurs joueurs de {@see ProgressionSnapshot::attributes()} et
 * {@see ProgressionSnapshot::vitality()}, pour la même raison que {@see LevelStanding} est le
 * pendant de {@see ProgressionSnapshot::standing()} : ce sont deux lectures d'un même snapshot
 * qui répondent à deux questions différentes — *combien* de niveau, *comment* de pratique —
 * et les garder distinctes garde visible ce que chaque appelant demande vraiment.
 *
 * Vitality voyage ici à côté des quatre autres alors qu'{@see AttributeGains} l'exclut
 * délibérément de sa propre forme : ce n'est pas une contradiction, la mise en garde du
 * docblock d'`AttributeGains` porte sur `toArray()`, pas sur l'idée de transporter les cinq
 * valeurs ensemble quand un appelant les veut au même instant, comme ici.
 */
final readonly class PlayerCharacteristics
{
    public function __construct(
        public AttributeGains $attributes,
        public int $vitality,
    ) {
    }

    /** Le joueur qui n'a encore rien fait : zéro partout, Vitality comprise. */
    public static function untouched(): self
    {
        return new self(new AttributeGains(0, 0, 0, 0), 0);
    }
}
