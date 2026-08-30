<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Shared\UI\Http\Cursor;
use Symfony\Component\Uid\Uuid;

/**
 * Une page du ledger de pièces d'un joueur — le pendant exact d'{@see \App\Progression\Application\ListXpHistory},
 * amputé des filtres pour la même raison qu'à `BattleHistoryQuery` (#220) : aucun écran n'en
 * demande.
 *
 * `userId` vient du jeton, jamais de l'URL — même remarque que sur `ListXpHistory` : ce
 * n'est pas un filtre parmi d'autres, c'est la condition qui rend la lecture légitime.
 */
final readonly class ListCoinHistory
{
    public function __construct(
        public Uuid $userId,
        public ?Cursor $cursor = null,
        public int $limit = 20,
    ) {
    }
}
