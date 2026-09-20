<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Deux joueurs sont-ils co-équipiers ? La seule question qu'un autre module a le droit de
 * poser à `Community` sans rien savoir des guildes.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** Deptrac
 * interdit à `Combat` d'importer quoi que ce soit de `Community`, et c'est heureux : le
 * défi PvP (#283) n'a aucun besoin de connaître une guilde, une adhésion ou un rôle — il a
 * besoin d'un booléen. Aucun composant Symfony ne répond à ça : c'est une frontière de
 * *notre* découpage, même raison et même endroit que {@see PlayerProfiles}.
 *
 * **Ce n'est pas un voter, et ce n'en sera pas un.**
 * {@see \App\Community\Infrastructure\Security\PlayerVoter} répond à « ai-je le droit de
 * *regarder* ce joueur » — une question d'autorisation, qui s'élargira le jour où les
 * classements arriveront. Celle-ci est un fait, pas un droit : l'appelant en tire la
 * conclusion qu'il veut, et pour le défi cette conclusion est un 404.
 */
interface Teammates
{
    /**
     * **Deux fois le même joueur rend `true`** — c'est vrai, et ce n'est pas la question :
     * un appelant qui doit refuser l'auto-défi le tranche avant d'appeler, comme
     * {@see \App\Community\Infrastructure\Security\PlayerVoter} le fait déjà pour « se voir
     * soi-même ».
     */
    public function share(Uuid $left, Uuid $right): bool;
}
