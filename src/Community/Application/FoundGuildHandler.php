<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Fonder une guilde. Le fondateur y entre du même geste — voir {@see Guild::found()}.
 *
 * **Deux gardes et non une, pour deux publics.** La lecture d'adhésion sert le joueur :
 * elle refuse tout de suite, sans écrire, celui qui a déjà une guilde. L'index unique sert
 * la correction : entre cette lecture et le `flush`, une seconde requête du même compte
 * passerait aussi. C'est la base qui tranche, et on traduit sa violation dans la **même**
 * erreur — l'appelant n'a qu'un cas à traiter, et il ne peut pas déduire du code d'erreur
 * qu'il a gagné ou perdu une course.
 */
final readonly class FoundGuildHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildMembershipRepository $memberships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyInAGuild
     */
    public function __invoke(FoundGuild $command): Guild
    {
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

        return $guild;
    }
}
