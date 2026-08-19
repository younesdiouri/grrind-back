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
 * production aux appareils des développeurs.
 *
 * **Le filtrage vit ici, pas chez l'appelant.** {@see \App\Identity\Infrastructure\Doctrine\UserDeviceRepository::of()}
 * — l'implémentation de {@see \App\Shared\Application\PushTargets} — ne rend que les jetons
 * de l'environnement courant, via {@see self::ofRuntimeEnvironment()}. Un notifier qui devrait
 * se souvenir de filtrer l'oubliera un jour ; c'est une règle de plateforme, pas une décision
 * du consommateur.
 */
enum DeviceEnvironment: string
{
    case Development = 'DEVELOPMENT';
    case Production = 'PRODUCTION';

    /**
     * Traduit `%kernel.environment%` (`dev`, `test`, `prod`) en `DeviceEnvironment`. Une
     * chaîne brute en entrée, pas un type Symfony : le domaine reste sans dépendance au
     * framework, et c'est l'appelant (le câblage de `UserDeviceRepository` dans
     * `services.yaml`) qui fait le pont.
     *
     * `test` tombe du côté `DEVELOPMENT` avec `dev` : la suite de tests n'est pas plus
     * proche de la production qu'un poste de développeur, et un jeton `PRODUCTION` ne doit
     * jamais être notifié pendant qu'elle tourne.
     */
    public static function ofRuntimeEnvironment(string $kernelEnvironment): self
    {
        return 'prod' === $kernelEnvironment ? self::Production : self::Development;
    }
}
