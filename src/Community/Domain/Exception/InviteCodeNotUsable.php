<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Le code ne mène à rien : **inconnu, expiré ou révoqué, indistinctement**.
 *
 * C'est tout l'intérêt de la classe qu'il n'y en ait qu'une. Distinguer « inconnu » de
 * « expiré » ferait de la route un oracle : un attaquant apprendrait quels codes ont
 * existé, ce qui réduit l'espace à explorer et permet de retenter les codes réels après
 * une régénération. Le message est écrit pour le joueur — « demande-lui de t'en renvoyer
 * un » — et il est le même dans les trois cas.
 *
 * 404 et non 422 : ce que l'appelant demande, c'est une invitation, et il n'y en a pas.
 */
final class InviteCodeNotUsable extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Ce code d\'invitation n\'est plus valable. Demande à un membre de la guilde de t\'en envoyer un nouveau.');
    }

    public function type(): string
    {
        return 'invite-code-not-usable';
    }
}
