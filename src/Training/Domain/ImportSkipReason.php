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
 * **Écarté ne veut pas toujours dire absent.** `OUT_OF_WINDOW` écarte le *crédit*, pas le
 * workout : la ligne est écrite, l'historique est là, elle ne rapporte simplement rien. Les
 * autres raisons n'écrivent rien du tout.
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

    /**
     * Trop vieux pour être crédité — mais **écrit quand même**, et c'est la seule raison
     * de cette liste qui laisse une ligne en base.
     *
     * C'est le garde-fou le plus important du virage santé. Un téléphone contient parfois
     * trois ans d'Apple Health : crédité tel quel, le joueur atteint le niveau 60 avant
     * d'avoir couru une fois *pour* Grrind, et le produit n'a plus rien à lui proposer.
     * Le ledger étant append-only, ça ne se rattrape pas après coup.
     *
     * Le joueur retrouve donc son passé sans le monnayer : le premier import devient un
     * moment de produit — « voilà tes six derniers mois » — au lieu d'un mur.
     */
    case OutOfWindow = 'OUT_OF_WINDOW';

    /**
     * Un autre workout couvre déjà ce créneau. Ce ne sont pas deux entraînements : c'est
     * **le même, vu par deux applications**. Apple Exercice, Strava et Nike Run Club
     * écrivent tous dans HealthKit, et beaucoup de gens en ont deux installées sans le
     * savoir.
     *
     * Sans cette règle, un joueur équipé de trois apps triple son XP sans rien faire
     * d'illégitime — c'est le cas **par défaut** chez une partie des utilisateurs, pas un
     * abus à punir.
     */
    case Overlaps = 'OVERLAPS';

    /**
     * Sous le plancher de durée. Douze secondes, c'est un faux départ sur la montre, pas
     * une séance — et l'écarter évite au joueur une ligne d'historique qu'il n'a pas vécue.
     */
    case TooShort = 'TOO_SHORT';
}
