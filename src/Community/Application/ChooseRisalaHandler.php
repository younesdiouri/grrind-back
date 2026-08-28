<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\RisalaTurnIsNotOpen;
use App\Community\Domain\Exception\RisalaTurnIsNotYours;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
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
 * **Aucun verrou.** Deux requêtes concurrentes du même joueur écriraient la même valeur, et
 * l'ordre entre deux choix différents du même joueur est celui qu'il a lui-même provoqué :
 * `PUT` deux fois donne le dernier, ce que le verbe promet. Le seul écrit concurrent qui
 * compte est celui de la bascule, et elle prend le verrou de la guilde — un choix qui
 * arriverait pendant qu'elle scelle est refusé par l'échéance, pas par une course.
 */
final readonly class ChooseRisalaHandler
{
    public function __construct(
        private RisalatBoardProvider $board,
        private RisalaRepository $risalat,
        private CreditingDisciplines $crediting,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChooseRisala $command): RisalatBoard
    {
        $guild = $this->board->guildOf($command->playerId);
        $now = $this->clock->now();

        $turn = $this->risalat->openTurnOf($guild) ?? throw new RisalaTurnIsNotOpen();

        if (!$turn->senderId()->equals($command->playerId)) {
            throw new RisalaTurnIsNotYours();
        }

        $turn->choose($command->discipline, $this->crediting, $this->challengedIn($guild, $now), $now);
        $this->risalat->commit();

        return $this->board->of($command->playerId);
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
