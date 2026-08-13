<?php

declare(strict_types=1);

namespace App\Training\Domain;

/**
 * Pourquoi un workout d'un lot n'a pas été crédité.
 *
 * **Écarter n'est pas refuser.** Un import est un ensemble, pas une transaction
 * tout-ou-rien : neuf séances valides ne peuvent pas échouer parce que la dixième est une
 * partie de curling. Aucune de ces raisons n'est donc une erreur HTTP — elles voyagent
 * dans la réponse, à côté de ce qui a été crédité.
 *
 * Et chacune est **nommée**, pas comptée. Une activité qui disparaît sans un mot est un bug
 * du point de vue du joueur, même quand c'est le comportement voulu ; le client doit
 * pouvoir écrire « ta séance de curling n'est pas encore un sport chez nous » plutôt que
 * « 1 séance ignorée ».
 *
 * #91 en ajoutera : fenêtre d'antériorité, chevauchement, durée sous le plancher.
 */
enum ImportSkipReason: string
{
    /**
     * Déjà en base sous le même couple (source, identifiant fournisseur). Le cas le plus
     * fréquent de tous, et le plus banal : un client qui a perdu son curseur de
     * synchronisation renvoie tout son historique, et c'est très bien.
     */
    case AlreadyImported = 'ALREADY_IMPORTED';

    /**
     * Aucune discipline ne correspond à ce type d'activité dans `activity_types.yaml`.
     *
     * Rien n'est écrit, donc rien n'est définitif : le jour où le sport entre dans la
     * table, le même workout renvoyé par le client est crédité. C'est le bénéfice direct
     * d'une table serveur — ouvrir un sport n'attend pas une version sur l'App Store, et
     * ne laisse pas les séances passées derrière.
     */
    case UnsupportedActivity = 'UNSUPPORTED_ACTIVITY';
}
