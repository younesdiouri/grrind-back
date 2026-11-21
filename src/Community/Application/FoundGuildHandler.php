<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildApplicationRepository;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Fonder une guilde. Le fondateur y entre du même geste — voir {@see Guild::found()}.
 *
 * Le verrou commun à toutes les adhésions du joueur couvre la création et le
 * retrait de ses candidatures. L’index unique garantit aussi l’unicité en base.
 */
final readonly class FoundGuildHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildApplicationRepository $applications,
        private GuildMembershipRepository $memberships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyInAGuild
     */
    public function __invoke(FoundGuild $command): Guild
    {
        return $this->guilds->transactional(function () use ($command): Guild {
            $this->applications->lockPlayer($command->founderId);
            if (null !== $this->memberships->ofPlayer($command->founderId)) {
                throw new PlayerAlreadyInAGuild();
            }

            $guild = Guild::found($command->name, $command->founderId, $this->clock->now());

            $this->guilds->add($guild);

            try {
                $this->guilds->commit();
            } catch (UniqueConstraintViolationException) {
                throw new PlayerAlreadyInAGuild();
            }

            $this->applications->withdrawForPlayer($command->founderId);

            return $guild;
        });
    }
}
