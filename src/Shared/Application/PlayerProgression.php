<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;

/**
 * Où en est un joueur, tel qu'un autre module l'affiche : un niveau, une barre, un titre —
 * et, depuis #176, les cinq caractéristiques.
 *
 * Le titre est un {@see PlayerTitle}, **la forme unique qu'a déjà toute l'API**. Servir un
 * titre allégé ici donnerait au client un second type à décoder et un second composant à
 * dessiner, pour le même objet — et les deux finiraient par diverger d'un champ.
 *
 * Le *prochain* titre n'y est pas : il n'a de sens que sur son propre profil, et c'est
 * pour ça que {@see PlayerTitles} reste un port distinct plutôt qu'une extension de
 * celui-ci. Deux besoins différents, deux contrats.
 *
 * **`attributes` et `vitality` y entrent par décision de produit, pas parce qu'ils ont fui
 * (#176).** La répartition d'une pratique a été tranchée sociale — c'est une des raisons
 * d'avoir des guildes — et ce port est la seule façon dont `Community` peut la voir, donc
 * l'élargir ici est le geste qui la rend visible partout où ce port est déjà consommé :
 * `GET /api/players/{id}` et la liste des membres, sans code en plus.
 *
 * **Ce que ce port n'a toujours pas le droit de porter ne change pas d'un mot** : ni
 * adresse, ni fuseau, ni rôle applicatif, ni rien qui identifie un compte plutôt qu'un
 * profil de jeu. Le prochain champ qui tenterait d'y entrer doit répondre à la même
 * question que celui-ci — « est-ce une donnée de jeu qu'on a décidé de partager » — et pas
 * se contenter d'être disponible.
 *
 * **`vitality` est bonifiée depuis #165**, comme sur `/api/progression` : la variété des
 * sports d'un co-équipier n'est pas la seule chose qu'une guilde donne envie de comparer,
 * son assiduité quotidienne aussi. `vitalityBreakdown` porte de quoi l'expliquer, pour la
 * même raison qu'à `ProgressionState` — voir son docblock.
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
        /** Les quatre caractéristiques, à l'instant présent — un état, jamais un passage. */
        public AttributeGains $attributes,
        /** La cinquième, bonifiée par l'énergie active de la fenêtre — voir le docblock de la classe. */
        public int $vitality,
        /** Ce qui explique `vitality` ci-dessus. */
        public VitalityBreakdown $vitalityBreakdown,
    ) {
    }

    /**
     * Le joueur qui n'a encore rien fait : niveau 1, barre à zéro, aucun titre, zéro sur les
     * cinq caractéristiques.
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
        return new self(1, 0, null, null, new AttributeGains(0, 0, 0, 0), 0, new VitalityBreakdown(0, 0, 0));
    }
}
