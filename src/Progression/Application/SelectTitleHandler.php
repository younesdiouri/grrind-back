<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\Exception\TitleIsNotUnlocked;
use App\Progression\Domain\Exception\TitleIsUnknown;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Infrastructure\Doctrine\ActiveTitleRepository;
use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use Psr\Clock\ClockInterface;

/**
 * Deux refus, dans cet ordre : un titre qu'on ne connaît pas, puis un titre qu'on n'a pas.
 *
 * Les distinguer est délibéré — l'un dit au client que son catalogue est périmé, l'autre
 * qu'il propose un titre verrouillé. Il n'y a rien à cacher ici : le catalogue est le même
 * pour tout le monde, et savoir qu'un titre existe n'est pas une information privée.
 */
final readonly class SelectTitleHandler
{
    public function __construct(
        private TitleCatalog $catalog,
        private UnlockedTitleRepository $unlockedTitles,
        private ActiveTitleRepository $activeTitles,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SelectTitle $command): void
    {
        if (null === $command->titleId) {
            $this->activeTitles->clear($command->userId);

            return;
        }

        $title = $this->catalog->findAvailable($command->titleId) ?? throw new TitleIsUnknown($command->titleId);

        if (!$this->unlockedTitles->holds($command->userId, $title->id)) {
            throw new TitleIsNotUnlocked($title->id);
        }

        $this->activeTitles->select($command->userId, $title->id, $this->clock->now());
    }
}
