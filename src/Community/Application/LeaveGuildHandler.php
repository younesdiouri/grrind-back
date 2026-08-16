<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;

/**
 * Le joueur sort de sa guilde. Trois issues, décidées par l'agrégat et non ici :
 * il s'en va simplement, il transmet au doyen, ou il éteint la lumière derrière lui.
 *
 * **Sous le verrou de la ligne de guilde**, comme le `join` (#116), et pour une raison
 * précise : le fondateur qui part pendant qu'un joueur arrive. Sans file d'attente, le
 * départ ne verrait pas l'arrivant, la succession se jouerait sur une composition périmée,
 * et une guilde pourrait se dissoudre à l'instant où quelqu'un y entre — le nouveau membre
 * se retrouvant dans une guilde disparue, donc enfermé hors de toute autre par l'index
 * unique.
 *
 * La dissolution passe par la même transaction : `cascade: remove` emporte les adhésions
 * et le code d'invitation, et rien ne survit à la guilde.
 */
final readonly class LeaveGuildHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildMembershipRepository $memberships,
    ) {
    }

    /**
     * @throws GuildNotFound
     */
    public function __invoke(LeaveGuild $command): void
    {
        $membership = $this->memberships->ofPlayer($command->playerId);

        if (null === $membership) {
            throw new GuildNotFound();
        }

        $guildId = $membership->guild()->id();

        $this->guilds->transactional(function () use ($command, $guildId): void {
            $guild = $this->guilds->lockForUpdate($guildId);

            if (null === $guild) {
                throw new GuildNotFound();
            }

            if ($guild->part($command->playerId)) {
                $this->guilds->dissolve($guild);
            }

            $this->guilds->commit();
        });
    }
}
