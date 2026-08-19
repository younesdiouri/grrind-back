<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Le code Expo derrière un jeton refusé — `DeviceNotRegistered` en tête, celui qui dira
 * au #131 qu'un jeton est mort et non victime d'un incident passager.
 *
 * **Ce code ne vient d'aucun champ structuré du bundle.** `ExpoTransport::doSend()`
 * (`symfony/expo-notifier`) le formate dans le message du `TransportException` qu'il
 * lève, sans l'exposer ailleurs :
 *
 *     sprintf('Unable to post the Expo message: "%s" (%s)', $message, $details['error'])
 *
 * — le code est le contenu entre parenthèses en toute fin de chaîne.
 * {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender} l'en extrait parce que rien
 * d'autre ne le porte ; c'est une dépendance à un format non documenté, propre à une
 * version du bundle, pas un champ de l'API Expo mal exposé. Si `symfony/expo-notifier`
 * change ce format, c'est un test qui casse le premier — pas un jeton mort qui reste
 * indéfiniment marqué vivant.
 *
 * Les quatre codes ci-dessous sont ceux que l'API Expo documente pour un ticket refusé.
 * `Unknown` couvre tout le reste : un message qui ne matche pas le format ci-dessus
 * (panne réseau, 5xx, format qui a changé) — jamais une exception, un envoi refusé ne
 * doit pas faire tomber les autres jetons du joueur.
 */
enum PushRejection: string
{
    case DeviceNotRegistered = 'DeviceNotRegistered';
    case MessageTooBig = 'MessageTooBig';
    case MessageRateExceeded = 'MessageRateExceeded';
    case InvalidCredentials = 'InvalidCredentials';
    case Unknown = 'Unknown';
}
