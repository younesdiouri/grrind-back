<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * « C'est ton tour » — à une seule personne, celle que la rotation vient de tirer.
 *
 * **La notification la plus coûteuse à manquer du produit** : la rater, c'est faire perdre une
 * semaine à toute la guilde, et le porteur n'a aucune raison d'ouvrir l'app ce dimanche-là
 * plutôt qu'un autre.
 *
 * C'est pour ça qu'elle est la seule à se **reporter** quand elle tombe dans les heures calmes
 * de son destinataire, au lieu d'être abandonnée comme le sont les annonces d'activité :
 * {@see AnnounceRisalaTurnHandler} se redispatche à la sortie de la plage. Une annonce
 * d'activité perdue est une nouvelle qu'on lira dans l'app ; un tour perdu est un tour perdu.
 *
 * Même remarque qu'à {@see AnnounceRisala} : il ne porte que l'identifiant du tour, et son
 * porteur se relit à la consommation.
 */
final readonly class AnnounceRisalaTurn
{
    public function __construct(public Uuid $risalaId)
    {
    }
}
