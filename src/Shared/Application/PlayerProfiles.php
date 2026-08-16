<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Le pseudo et la date d'inscription de plusieurs joueurs, pour les modules qui doivent les
 * afficher sans posséder les comptes.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** Le
 * `displayName` appartient à `Identity`. La liste des membres d'une guilde est servie par
 * `Community`, et Deptrac lui interdit d'importer une entité d'`Identity` — heureusement,
 * c'est le genre de flèche qui ne se retire plus jamais. Aucun composant Symfony ne répond
 * à ça : c'est une frontière de *notre* découpage. Même raison que {@see PlayerTimezones}
 * et {@see PlayerTitles}, même endroit.
 *
 * **Le contrat est batch par construction, et c'est la seule décision qui compte ici.** Un
 * port qui rendrait un joueur à la fois deviendrait un N+1 au premier écran : trente
 * membres, trente requêtes, et le problème n'apparaîtrait qu'en production sur les grosses
 * guildes. En prenant une liste, la signature elle-même interdit d'écrire la boucle — on
 * ne peut pas dériver vers ce qu'on ne peut pas appeler.
 */
interface PlayerProfiles
{
    /**
     * Les profils demandés, **en une seule requête**, indexés par UUID en RFC 4122.
     *
     * Un joueur inconnu est **absent de la table de retour**, il ne provoque ni `null` dans
     * une liste ni exception : l'appelant demande ce qu'il veut afficher, et ce qui manque
     * ne s'affiche pas. Faire échouer l'appel ferait rater l'écran entier pour une ligne.
     *
     * @param list<Uuid> $playerIds
     *
     * @return array<string, PlayerProfile>
     */
    public function of(array $playerIds): array;
}
