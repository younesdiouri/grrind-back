<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Translation;

use App\Progression\Application\TitleBoardProvider;
use App\Shared\Application\PlayerTitles;
use App\Shared\Application\PlayerTitleStanding;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see PlayerTitles} : c'est par cette classe, et uniquement par
 * elle, qu'`Identity` voit les titres d'un joueur.
 *
 * Elle ne fait qu'assembler — le mur des titres d'un côté, les mots de l'autre. La règle
 * qu'elle applique tient en une phrase : ce qui sort d'ici est **déjà traduit et déjà
 * situé**, pour qu'`Identity` n'ait ni catalogue à consulter ni condition à interpréter.
 */
final readonly class TranslatedPlayerTitles implements PlayerTitles
{
    public function __construct(
        private TitleBoardProvider $boards,
        private TitleTranslator $titles,
    ) {
    }

    public function of(Uuid $userId): PlayerTitleStanding
    {
        $board = $this->boards->of($userId);
        $active = $board->active();

        return new PlayerTitleStanding(
            null === $active ? null : $this->titles->describe($active, $board->unlockedAtOf($active->title->id)),
            null === $board->next ? null : $this->titles->describe($board->next, null),
        );
    }
}
