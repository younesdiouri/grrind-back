<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Il n'y a pas de tour à honorer dans cette guilde.
 *
 * Deux situations, et aucune des deux n'est une anomalie : la guilde n'a qu'un membre — on
 * n'y tire personne, un défi qu'on s'envoie à soi-même n'en est pas un — ou elle vient d'être
 * fondée et attend la bascule hebdomadaire.
 *
 * 404 plutôt que 409 : ce n'est pas un état transitoire de la ressource, c'est l'absence de
 * la ressource. Il n'y a rien à modifier à cette adresse.
 */
final class RisalaTurnIsNotOpen extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Aucun tour n\'est ouvert dans cette guilde.');
    }

    public function type(): string
    {
        return 'risala-turn-is-not-open';
    }
}
