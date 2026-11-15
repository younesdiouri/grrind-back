<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Exception\RisalaTurnIsNotOpen;
use App\Community\Domain\Exception\RisalaTurnIsNotYours;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Enregistre le choix du membre tiré, et rend l'écran mis à jour.
 *
 * **Les deux refus prononcés ici sont ceux que le domaine ne peut pas voir** : il n'y a pas
 * de tour ouvert, ou il appartient à quelqu'un d'autre. Les trois autres — échéance passée,
 * discipline sans XP, discipline déjà défiée — vivent dans {@see Risala::choose()}, parce
 * qu'ils portent sur l'objet lui-même et qu'un contrôleur qui les rejouerait finirait par
 * diverger de lui.
 *
 * Le choix et la bascule partagent la transaction et le verrou de guilde : un choix
 * validé sur DRAWN ne doit jamais être écrit après SENT/MISSED. Après l'attente, adhésion,
 * tour et heure se relisent ; l'identity map et l'heure d'entrée ne font pas autorité.
 */
final readonly class ChooseRisalaHandler
{
    public function __construct(
        private RisalatBoardProvider $board,
        private RisalaRepository $risalat,
        private CreditingDisciplines $crediting,
        private ClockInterface $clock,
        private GuildRepository $guilds,
    ) {
    }

    public function __invoke(ChooseRisala $command): RisalatBoard
    {
        $guildId = $this->board->guildOf($command->playerId)->id();

        return $this->guilds->transactional(function () use ($command, $guildId): RisalatBoard {
            $guild = $this->guilds->lockForUpdate($guildId) ?? throw new GuildNotFound();

            if (!$guild->hasMember($command->playerId)) {
                throw new GuildNotFound();
            }

            $turn = $this->risalat->openTurnOf($guild) ?? throw new RisalaTurnIsNotOpen();

            if (!$turn->senderId()->equals($command->playerId)) {
                throw new RisalaTurnIsNotYours();
            }

            $now = $this->clock->now();
            $turn->choose($command->discipline, $this->crediting, $this->challengedIn($guild, $now), $now);
            $this->risalat->commit();

            return $this->board->of($command->playerId);
        });
    }

    /**
     * Les disciplines des Risālāt vivantes, dans la forme que l'agrégat consomme.
     *
     * @return list<Discipline>
     */
    private function challengedIn(Guild $guild, DateTimeImmutable $now): array
    {
        return array_values(array_filter(array_map(
            static fn (Risala $risala): ?Discipline => $risala->discipline(),
            $this->risalat->liveIn($guild, $now),
        )));
    }
}
