<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Les jetons de push joignables d'un joueur, pour le module qui devra le notifier.
 *
 * **Pourquoi un port.** L'appareil qu'un compte a enregistré vit dans `Identity`
 * ({@see \App\Identity\Domain\UserDevice}), posé au #129 comme préalable du jalon
 * Notifications. `Community` est le premier consommateur prévu — une guilde notifie ses
 * membres sans jamais avoir importé une entité `Identity` — et rien ne dit qu'il restera le
 * seul : `Progression` (montée de niveau) et `Engagement` (streak en péril) sont des
 * candidats naturels. Le contrat vit donc dans `Shared`, comme les six autres ports déjà en
 * place, plutôt que dans `Identity` où seul `Identity` pourrait l'importer.
 *
 * **La forme est un seul `Uuid` en entrée, pas une liste.** `PlayerProfiles` et
 * `PlayerProgressions` prennent une liste parce qu'un écran de guilde demande N joueurs
 * d'un coup ; notifier reste, pour l'instant, un événement qui vise un joueur précis (son
 * co-équipier a rejoint, sa séance a été créditée). Si un consommateur en vient à notifier
 * une guilde entière d'un coup, le N+1 se réglera comme les deux autres l'ont réglé — en
 * élargissant la signature, pas en la devinant aujourd'hui pour un besoin qui n'existe pas
 * encore.
 *
 * Le jeton est une chaîne Expo (`ExponentPushToken[…]`), pas un device token APNs brut :
 * c'est symfony/expo-notifier qui portera l'appel HTTP, posé aux tickets suivants (#130+).
 */
interface PushTargets
{
    /**
     * @return list<string> les jetons Expo joignables, vide si le joueur n'a enregistré
     *                      aucun appareil ou n'existe pas — pas d'exception : un
     *                      destinataire introuvable n'est pas une erreur, juste rien à
     *                      notifier
     */
    public function of(Uuid $userId): array;
}
