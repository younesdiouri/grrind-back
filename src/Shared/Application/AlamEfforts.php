<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Training fournit les bornes et métriques des séances qui chevauchent une fenêtre.
 *
 * **La date est le filtre, jamais un jeu d'identifiants.** Progression demandait autrefois
 * les séances d'une liste de sources créditées, liste qu'elle tirait de tout le ledger du
 * joueur : une jauge de guilde hydratait alors l'historique complet de chacun de ses
 * membres, à chaque relecture de l'édition en cours. La fenêtre borne la requête ici, et
 * c'est Progression qui écarte ensuite ce que son ledger n'a pas crédité.
 */
interface AlamEfforts
{
    /** @return list<AlamEffort> */
    public function within(Uuid $player, DateTimeImmutable $start, DateTimeImmutable $end, GameRulesets $rules): array;
}
