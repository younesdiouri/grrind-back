<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\TrainingSession;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use Psr\Clock\ClockInterface;

/**
 * Ouvre la séance et la date. Le handler paraît mince pour l'instant, et c'est
 * délibéré : c'est ici qu'atterriront les garde-fous — unicité de la séance active,
 * cooldown entre deux séances — parce qu'ils ont besoin des *autres* séances du
 * joueur, ce que l'agrégat ne peut pas savoir tout seul. Les poser dans le
 * contrôleur reviendrait à les rendre intestables sans HTTP.
 */
final readonly class StartSessionHandler
{
    public function __construct(
        private TrainingSessionRepository $sessions,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StartSession $command): TrainingSession
    {
        $session = TrainingSession::start($command->userId, $command->discipline, $this->clock->now());

        $this->sessions->add($session);
        $this->sessions->commit();

        return $session;
    }
}
