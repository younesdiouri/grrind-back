<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * « La Risāla de la semaine est partie » — à toute la guilde, au même instant.
 *
 * **Publié depuis la bascule, dans sa transaction, consommé après le `COMMIT`.** Le transport
 * Doctrine partage la connexion, donc l'écriture du message participe au même `COMMIT` que la
 * révélation : on n'annonce jamais un fait encore annulable, et on ne perd jamais une annonce
 * dont le fait est acquis. C'est la même mécanique d'outbox qu'au #133.
 *
 * Il ne porte que l'identifiant de la Risāla. Tout le reste — la discipline, l'expéditeur, les
 * destinataires — se relit à la consommation : entre la publication et l'envoi, quelqu'un peut
 * avoir quitté la guilde, et un message qui transporterait sa liste de destinataires
 * notifierait un état périmé.
 */
final readonly class AnnounceRisala
{
    public function __construct(public Uuid $risalaId)
    {
    }
}
