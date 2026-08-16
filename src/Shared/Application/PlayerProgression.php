<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Où en est un joueur, tel qu'un autre module l'affiche : un niveau, une barre, un titre.
 *
 * Le titre est un {@see PlayerTitle}, **la forme unique qu'a déjà toute l'API**. Servir un
 * titre allégé ici donnerait au client un second type à décoder et un second composant à
 * dessiner, pour le même objet — et les deux finiraient par diverger d'un champ.
 *
 * Le *prochain* titre n'y est pas : il n'a de sens que sur son propre profil, et c'est
 * pour ça que {@see PlayerTitles} reste un port distinct plutôt qu'une extension de
 * celui-ci. Deux besoins différents, deux contrats.
 */
final readonly class PlayerProgression
{
    public function __construct(
        public int $level,
        /** XP acquise depuis le seuil du niveau. Le numérateur de la barre. */
        public int $xpIntoLevel,
        /** Ce qui reste avant le niveau suivant, ou `null` au niveau maximum. */
        public ?int $xpToNextLevel,
        /** `null` si le joueur n'en porte aucun — un cas normal, pas une anomalie. */
        public ?PlayerTitle $title,
    ) {
    }

    /**
     * Le joueur qui n'a encore rien fait : niveau 1, barre à zéro, aucun titre.
     *
     * **Il existe pour qu'aucun profil incomplet ne fasse rater un écran.** Un compte
     * inscrit il y a une minute n'a pas de ligne de progression — c'est le premier crédit
     * qui la pose — et il peut parfaitement être membre d'une guilde. Lever ici ferait
     * disparaître la liste entière à cause de lui.
     *
     * Le niveau 1 est écrit en clair plutôt que lu sur la courbe : `Shared` ne connaît pas
     * `Progression`, et l'implémentation qui a la courbe sous la main s'en sert, elle.
     */
    public static function untouched(): self
    {
        return new self(1, 0, null, null);
    }
}
