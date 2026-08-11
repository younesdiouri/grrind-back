<?php

declare(strict_types=1);

namespace App\Progression\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * L'identifiant demandé n'est dans aucun catalogue. Le cas courant n'est pas la faute de
 * frappe mais le **titre retiré** : un client resté ouvert propose encore un titre que le
 * déploiement d'hier a supprimé.
 */
final class TitleIsUnknown extends NotFoundError
{
    public function __construct(string $titleId)
    {
        parent::__construct(\sprintf('Aucun titre "%s" au catalogue.', $titleId), ['titleId' => $titleId]);
    }

    public function type(): string
    {
        return 'title-unknown';
    }
}
