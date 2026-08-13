<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\Exception\WorkoutAlreadyActive;
use App\Training\Domain\Exception\WorkoutInCooldown;
use App\Training\Domain\Workout;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Ouvre la séance, si les garde-fous le permettent. Ils sont ici et non dans l'agrégat
 * parce qu'ils portent sur les *autres* séances du joueur — unicité de l'active et
 * cooldown sont des règles sur son historique.
 */
final readonly class StartSessionHandler
{
    public function __construct(
        private WorkoutRepository $sessions,
        private ClockInterface $clock,
        private WorkoutRules $rules,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws WorkoutAlreadyActive une séance tourne déjà
     * @throws WorkoutInCooldown    la précédente est trop récente
     */
    public function __invoke(StartSession $command): Workout
    {
        $now = $this->clock->now();

        $active = $this->sessions->activeOf($command->userId);

        if (null !== $active) {
            throw new WorkoutAlreadyActive($active->id());
        }

        $this->ensureCooldownElapsed($command, $now);

        $session = Workout::start($command->userId, $command->discipline, $now);

        $this->sessions->add($session);

        try {
            $this->sessions->commit();
        } catch (UniqueConstraintViolationException $collision) {
            // Deux ouvertures simultanées : l'index partiel n'en garde qu'une, et le
            // perdant reçoit la même erreur que s'il était arrivé en second.
            $this->logger->info('Ouverture de séance concurrente rejetée par l\'index unique.', [
                'userId' => $command->userId->toRfc4122(),
                'exception' => $collision,
            ]);

            throw new WorkoutAlreadyActive($this->sessions->activeIdOf($command->userId) ?? $session->id());
        }

        return $session;
    }

    /**
     * @throws WorkoutInCooldown
     */
    private function ensureCooldownElapsed(StartSession $command, DateTimeImmutable $now): void
    {
        $previous = $this->sessions->lastCountedClosure($command->userId, $this->rules->minimumDurationSeconds);
        $endedAt = $previous?->endedAt();

        if (null === $endedAt) {
            return;
        }

        $readyAt = $endedAt->modify(\sprintf('+%d seconds', $this->rules->cooldownSeconds));
        $remaining = $readyAt->getTimestamp() - $now->getTimestamp();

        if ($remaining > 0) {
            throw new WorkoutInCooldown($readyAt, $remaining);
        }
    }
}
