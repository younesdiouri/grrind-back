<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Pousse une notification vers les appareils joignables d'un joueur.
 *
 * **Pourquoi un port, et pas le Notifier injecté partout.** `symfony/expo-notifier`
 * parle en `to`/`title`/`body`/`data` ; le domaine parle en `category` et
 * `groupingKey` ({@see PushNotification}). Sans ce port, chaque consommateur — Community
 * en premier, Progression et Engagement ensuite comme {@see PushTargets} le prévoit
 * déjà — referait cette traduction lui-même, et l'un des trois l'oublierait. C'est la
 * même raison que {@see \App\Identity\Application\SocialProfileResolver} : le port
 * existe parce qu'une traduction de vocabulaire réelle a lieu, pas pour découpler par
 * principe.
 *
 * **Un seul `Uuid` en entrée, comme {@see PushTargets}.** Les deux ports se ressemblent
 * volontairement — posé juste après lui, au #130, sur le même besoin : notifier reste un
 * événement qui vise un joueur précis.
 *
 * **Ne résout pas les jetons lui-même dans le contrat.** L'implémentation s'appuie sur
 * {@see PushTargets} pour ça ; le consommateur ne voit jamais un jeton Expo, seulement un
 * `Uuid` de joueur.
 */
interface PushSender
{
    /**
     * @return list<PushTicket> un ticket par jeton joignable du joueur, dans l'ordre où
     *                          {@see PushTargets::of()} les a rendus — vide si le joueur
     *                          n'a aucun appareil enregistré, pas d'exception : voir
     *                          {@see PushTargets} pour la même règle
     */
    public function send(Uuid $userId, PushNotification $notification): array;
}
