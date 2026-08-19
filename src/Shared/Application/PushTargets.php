<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\NotificationCategory;
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
 *
 * **Déjà filtré sur l'environnement d'exécution.** `UserDevice` porte un `DeviceEnvironment`
 * (`DEVELOPMENT`/`PRODUCTION`) parce qu'un même joueur peut avoir un jeton de chaque —
 * client de dev Expo et build publié. Rendre les deux ici obligerait chaque consommateur à
 * connaître cette distinction et à se souvenir de filtrer ; un notifier qui l'oublie envoie
 * une campagne de production aux téléphones des développeurs. Le filtrage est donc une
 * règle de plateforme tranchée dans l'implémentation
 * ({@see \App\Identity\Infrastructure\Doctrine\UserDeviceRepository::of()}), pas ici dans
 * le contrat ni chez l'appelant.
 *
 * **Depuis le #132, filtré aussi sur la préférence du compte.** Une catégorie coupée dans
 * les préférences du joueur ({@see \App\Identity\Domain\User::notifiesOn()}) rend une
 * liste **vide**, jamais la liste complète à filtrer chez l'appelant — c'est le lien
 * explicite que le ticket posait : « un joueur qui a coupé la catégorie n'est pas une
 * cible, plutôt qu'une cible qu'on filtre à l'envoi ». La préférence vit sur le compte et
 * non sur `UserDevice` : couper une catégorie sur l'iPhone la coupe aussi pour l'iPad du
 * même joueur, elle ne se règle pas appareil par appareil.
 */
interface PushTargets
{
    /**
     * @return list<string> les jetons Expo joignables **de l'environnement courant**, pour
     *                      cette catégorie — vide si le joueur n'a enregistré aucun
     *                      appareil, n'existe pas, ou a coupé la catégorie dans ses
     *                      préférences — pas d'exception : un destinataire introuvable
     *                      n'est pas une erreur, juste rien à notifier
     */
    public function of(Uuid $userId, NotificationCategory $category): array;
}
