<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Efface un jeton de push qu'Expo vient de déclarer mort.
 *
 * **Pourquoi un port.** L'appareil qu'un jeton identifie vit dans `Identity`
 * ({@see \App\Identity\Domain\UserDevice}), et c'est `Shared\Infrastructure\Notifier`
 * ({@see \App\Shared\Infrastructure\Notifier\ExpoPushSender}) qui apprend qu'il est mort —
 * `Shared` ne peut pas importer une entité `Identity`. Même frontière que
 * {@see PushTargets}, posée par le même module pour le problème inverse (lire les jetons
 * plutôt que les effacer) : le contrat vit ici, l'implémentation reste
 * {@see \App\Identity\Infrastructure\Doctrine\UserDeviceRepository}.
 *
 * **Un port séparé de `PushTargets`, pas une méthode de plus dessus.** Les deux portent
 * sur la même table, mais pas la même intention : `PushTargets::of()` est une lecture pour
 * qui doit notifier, `discard()` est une écriture pour qui vient d'apprendre qu'un
 * appareil a disparu. Un seul consommateur de chacun aujourd'hui (respectivement
 * `Community` à terme, et `ExpoPushSender`) ; les fusionner obligerait le premier à
 * dépendre d'une capacité d'écriture qu'il n'utilise jamais.
 */
interface DeadPushTokens
{
    /**
     * Sans effet si `$pushToken` ne correspond à aucun appareil enregistré — un jeton déjà
     * effacé (par un envoi concurrent, ou déjà réclamé par un autre compte entre-temps)
     * n'est pas une erreur, juste plus rien à faire.
     */
    public function discard(string $pushToken): void;
}
