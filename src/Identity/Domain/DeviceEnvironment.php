<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Le canal de build qui a émis le jeton, distinct de la plateforme : un même iPhone
 * produit un jeton `DEVELOPMENT` en client de dev Expo et un jeton `PRODUCTION` une fois
 * publié sur le store, et ce sont deux jetons Expo différents pour le même appareil.
 *
 * Expo Push Service ne distingue pas sandbox et production comme le faisait APNs brut — le
 * ruleset de routage vit côté Expo — mais **le serveur, lui, doit savoir lesquels sont des
 * builds de test** : notifier tous les jetons sans distinction enverrait les campagnes de
 * production aux appareils des développeurs. Le champ est donc un attribut du jeton, pas
 * une inférence côté serveur.
 */
enum DeviceEnvironment: string
{
    case Development = 'DEVELOPMENT';
    case Production = 'PRODUCTION';
}
