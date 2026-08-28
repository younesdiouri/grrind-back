<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Où en est une Risāla. Trois valeurs, et la troisième dit qu'il ne s'est rien passé.
 *
 * **Il n'y a pas de `EXPIRED`, et c'est délibéré.** « Vivante » se lit sur les dates —
 * `revealedAt <= t < expiresAt` — jamais sur ce champ. Un statut d'expiration obligerait un
 * traitement périodique à passer *avant* toute lecture pour que la réponse soit vraie ;
 * une bascule manquée laisserait alors une Risāla morte continuer de bonifier des séances,
 * en silence, et c'est le genre de panne qu'on ne découvre qu'au ledger.
 *
 * Le statut ne répond donc qu'à une question que les dates ne savent pas poser : ce tour
 * a-t-il produit quelque chose.
 */
enum RisalaStatus: string
{
    /** Un membre a été tiré, il a jusqu'à son échéance pour choisir. Au plus un par guilde. */
    case Drawn = 'DRAWN';

    /** Le tour a produit une Risāla, révélée à toute la guilde. */
    case Sent = 'SENT';

    /**
     * L'échéance est passée sans choix — ou son porteur avait quitté la guilde. Le tour est
     * **consommé quand même** : la rotation avance, sinon un membre passif gèlerait le cycle
     * pour tout le monde. Une semaine sans nouvelle Risāla, jamais plus.
     */
    case Missed = 'MISSED';
}
