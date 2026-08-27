<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * L'énergie active moyenne d'un joueur sur une fenêtre glissante de journées — ce que
 * {@see \App\Shared\Domain\Activity\Vitality::bonused()} attend en paramètre, jamais allé
 * chercher par elle (#165).
 *
 * **Pourquoi un port.** `daily_activity` est possédée par `Training` — c'est lui qui reçoit
 * l'ingestion santé — mais c'est `Progression` qui affiche la Vitality bonifiée sur
 * `/api/progression` et le profil public. Deptrac refuse à `Progression` d'importer
 * l'entité `DailyActivity` ; ce port est la seule voie entre les deux, `Training`
 * l'implémente, `Progression` le consomme, et aucune flèche ne va de l'un à l'autre.
 *
 * **Batch par construction**, comme {@see PlayerProfiles} et {@see PlayerProgressions} : le
 * profil public d'une guilde interroge plusieurs joueurs d'un coup, et un contrat qui n'en
 * rendrait qu'un forcerait une boucle de N requêtes à l'appelant — exactement la dérive que
 * ces deux ports existent pour empêcher.
 *
 * **Une journée absente compte pour zéro**, y compris pour un joueur qui n'a jamais rien
 * envoyé : la moyenne se calcule toujours sur `windowDays` jours, jamais sur le nombre de
 * lignes trouvées. C'est ce qui mesure la sédentarité plutôt que de l'ignorer — voir le
 * docblock du ticket #165 pour pourquoi ce n'est pas une donnée manquante comme une autre.
 */
interface ActiveEnergyWindows
{
    /**
     * La moyenne, par joueur, sur les `windowDays` journées se terminant `$endingOn`
     * **incluse**.
     *
     * `$endingOn` ne se déduit pas ici : c'est l'appelant qui sait dans quel fuseau situer
     * « aujourd'hui » pour le joueur qu'il sert — voir le docblock de
     * `TranslatedPlayerProgressions` pour le compromis retenu sur un lot de plusieurs
     * joueurs, potentiellement dans des fuseaux différents.
     *
     * Rend une entrée pour **chaque** identifiant demandé, `0` compris : un joueur sans
     * aucune ligne n'est pas absent de la table de retour, il vaut zéro sur toute la
     * fenêtre — même contrat que {@see PlayerProgressions::of()} pour la même raison, un
     * joueur qui vient de s'inscrire ne doit pas faire échouer l'affichage des autres.
     *
     * @param list<Uuid> $userIds
     *
     * @return array<string, int> indexé par UUID en RFC 4122
     */
    public function averagesOf(array $userIds, DateTimeImmutable $endingOn, int $windowDays): array;
}
