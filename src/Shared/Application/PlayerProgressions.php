<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Le niveau, le titre porté et les cinq caractéristiques de plusieurs joueurs, pour les
 * modules qui les affichent sans posséder le ledger.
 *
 * Le pendant exact de {@see PlayerProfiles}, dans l'autre module et pour la même raison :
 * `Progression` possède la courbe, les snapshots et les titres ; `Community` dessine une
 * liste de membres et n'a le droit d'importer aucun des trois.
 *
 * **Batch par construction**, comme son jumeau : la signature prend une liste pour qu'un
 * appelant ne *puisse pas* écrire la boucle qui ferait trente requêtes sur une guilde de
 * trente. C'est le genre de dérive qu'on n'attrape pas en revue mais en production.
 *
 * **On ne le fusionne pas avec {@see PlayerTitles}.** Celui-là rend aussi le *prochain*
 * titre visé, qui n'a de sens que sur son propre profil — personne n'a à savoir ce qu'un
 * co-équipier est en train de viser. Deux besoins différents, deux contrats, et le plus
 * indiscret des deux reste cantonné à `GET /api/me`.
 */
interface PlayerProgressions
{
    /**
     * Les progressions demandées, en un **nombre constant de requêtes**, indexées par UUID
     * en RFC 4122.
     *
     * Un joueur sans ligne de progression rend {@see PlayerProgression::untouched()} et
     * **jamais une exception** : il vient de s'inscrire, c'est le premier crédit qui pose
     * sa ligne, et il peut très bien avoir rejoint une guilde entre-temps. Un profil
     * incomplet ne doit pas faire rater l'affichage de la liste entière.
     *
     * Contrairement à {@see PlayerProfiles}, la table de retour est donc **complète** : tout
     * identifiant demandé y figure. La différence n'est pas un oubli — un joueur sans
     * progression a un état neutre parfaitement affichable, alors qu'un joueur sans compte
     * n'a pas de pseudo neutre à montrer.
     *
     * @param list<Uuid> $playerIds
     *
     * @return array<string, PlayerProgression>
     */
    public function of(array $playerIds): array;
}
