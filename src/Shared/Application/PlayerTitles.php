<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Les titres d'un joueur, pour les modules qui doivent les afficher sans les posséder.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** Le
 * catalogue, les conditions et les déblocages appartiennent à `Progression`. Le profil, lui,
 * est servi par `Identity` — et le ticket demande le titre actif dans `GET /api/me`, parce
 * que le client a besoin de l'état du joueur en une requête à l'ouverture de l'app. Deptrac
 * interdit à `Identity` d'importer une entité de `Progression`, et c'est heureux : c'est le
 * genre de flèche qui ne se retire plus jamais.
 *
 * Aucun composant Symfony ne répond à ça : c'est une frontière de *notre* découpage. Le port
 * vit donc dans `Shared`, comme {@see PlayerTimezones} et pour la même raison — les deux
 * côtés n'en dépendent que par là, et aucune flèche ne va de l'un à l'autre.
 *
 * Le contrat est volontairement minuscule : un UUID entre, deux titres déjà traduits
 * sortent. `Identity` n'apprend ni ce qu'est une condition, ni comment on désigne le
 * prochain titre.
 */
interface PlayerTitles
{
    /**
     * Le titre porté et le prochain à viser.
     *
     * Jamais `null`, jamais d'exception : un joueur sans aucun titre rend
     * {@see PlayerTitleStanding::none()}. Faire échouer l'appel ferait rater l'affichage
     * d'un profil pour une raison qui n'a rien à voir avec le profil.
     */
    public function of(Uuid $userId): PlayerTitleStanding;
}
