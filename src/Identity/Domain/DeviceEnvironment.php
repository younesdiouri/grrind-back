<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Le canal de build qui a émis le jeton, distinct de la plateforme : un même iPhone
 * produit un jeton `DEVELOPMENT` en client de dev Expo et un jeton `PRODUCTION` une fois
 * publié sur le store, et ce sont deux jetons Expo différents pour le même appareil.
 *
 * **Ce que ce champ dit, et ce qu'il ne dit plus (#149).** Il décrit uniquement quel canal
 * APNs a émis *ce jeton précis* — rien sur le déploiement qui l'a produit. Il a longtemps
 * porté une seconde intention, fausse : distinguer un build de développeur d'un build de
 * store. Elle ne tient pas — **tout build EAS produit un jeton `PRODUCTION`**, y compris le
 * client de dev en `distribution: internal`, la preview, TestFlight et l'App Store ; seul un
 * `expo run:ios` local signé par un profil de développement produit `DEVELOPMENT`. Le champ
 * ne peut donc plus servir à protéger qui que ce soit d'une campagne : un jeton `PRODUCTION`
 * n'est pas plus « un vrai joueur » qu'un jeton `DEVELOPMENT` n'est « un développeur ».
 *
 * **Quel canal ce déploiement adresse est une question différente**, tranchée par le réglage
 * `PUSH_TARGET_ENVIRONMENT` ({@see \App\Identity\Infrastructure\Doctrine\UserDeviceRepository})
 * — jamais déduite de `%kernel.environment%`, qui décrit le serveur, pas le jeton. Les deux
 * axes ne se confondent plus : un serveur de dev peut viser `PRODUCTION` (le cas normal,
 * puisque c'est ce que tout build EAS produit) sans que ça dise quoi que ce soit sur son
 * propre environnement d'exécution.
 */
enum DeviceEnvironment: string
{
    case Development = 'DEVELOPMENT';
    case Production = 'PRODUCTION';
}
