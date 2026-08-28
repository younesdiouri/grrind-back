<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ForbiddenError;

/**
 * Le tour ouvert appartient à quelqu'un d'autre.
 *
 * **403 et non 404, contrairement à la règle du module** — et l'exception est raisonnée : le
 * refus ne protège rien. L'appelant est membre de la guilde, `GET /api/guilds/mine/risalat`
 * lui a déjà dit à qui appartient le tour, et le lui redire dans un code de statut ne lui
 * apprend rien qu'il ne puisse lire à l'écran.
 *
 * Le 404 sert à cacher l'*existence* d'une ressource à qui n'a rien à y voir. Ici, la
 * ressource est visible et c'est l'action qui est refusée : c'est exactement la frontière
 * tracée par {@see \App\Community\UI\Http\VisibleGuildResolver}.
 */
final class RisalaTurnIsNotYours extends ForbiddenError
{
    public function __construct()
    {
        parent::__construct('Ce n\'est pas votre tour d\'envoyer la Risāla de la semaine.');
    }

    public function type(): string
    {
        return 'risala-turn-is-not-yours';
    }
}
