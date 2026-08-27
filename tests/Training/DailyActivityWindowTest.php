<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Domain\Activity\WorkoutSource;
use App\Tests\Support\ApiTestCase;
use App\Training\Infrastructure\Doctrine\DailyActivityRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * `DailyActivityRepository`, l'implémentation du port `ActiveEnergyWindows` (#165) : la
 * fenêtre glissante, la révision d'un jour et le lot de plusieurs joueurs d'un coup.
 *
 * C'est ici, pas dans `VitalityTest`, que se prouve « une journée absente compte pour
 * zéro » — `Vitality` ne voit jamais de journée, seulement la moyenne déjà calculée.
 */
final class DailyActivityWindowTest extends ApiTestCase
{
    private DailyActivityRepository $activities;

    protected function setUp(): void
    {
        parent::setUp();

        $activities = self::getContainer()->get(DailyActivityRepository::class);
        self::assertInstanceOf(DailyActivityRepository::class, $activities);
        $this->activities = $activities;
    }

    /**
     * Sept jours de fenêtre, un seul jour renseigné : la moyenne divise par sept, pas par
     * un — sans quoi un joueur actif un seul jour afficherait la moyenne de ce jour-là.
     */
    public function testAMissingDayCountsAsZeroInTheAverage(): void
    {
        $player = $this->openAccount()->id;

        $this->activities->upsert($player, new DateTimeImmutable('2026-08-20'), 700, WorkoutSource::AppleHealth, new DateTimeImmutable());

        $averages = $this->activities->averagesOf([$player], new DateTimeImmutable('2026-08-20'), 7);

        self::assertSame(100, $averages[$player->toRfc4122()], '700 kcal sur une fenêtre de 7 jours, dont 6 absents : la moyenne est 100, pas 700.');
    }

    /**
     * Une journée hors fenêtre ne compte pas du tout, même à un jour près.
     */
    public function testADayOutsideTheWindowDoesNotCount(): void
    {
        $player = $this->openAccount()->id;

        $this->activities->upsert($player, new DateTimeImmutable('2026-08-12'), 700, WorkoutSource::AppleHealth, new DateTimeImmutable());

        // Fenêtre de 7 jours se terminant le 20 : elle commence le 14, le 12 est hors champ.
        $averages = $this->activities->averagesOf([$player], new DateTimeImmutable('2026-08-20'), 7);

        self::assertSame(0, $averages[$player->toRfc4122()]);
    }

    /**
     * Le cœur du ticket : 4 000 kcal à 14 h puis 11 000 à 22 h ne sont pas deux journées —
     * `upsert()` deux fois sur le même `(user, day)` révise, il ne cumule pas.
     */
    public function testUpsertingTheSameDayTwiceRevisesRatherThanAccumulates(): void
    {
        $player = $this->openAccount()->id;
        $day = new DateTimeImmutable('2026-08-20');

        $this->activities->upsert($player, $day, 400, WorkoutSource::AppleHealth, new DateTimeImmutable('2026-08-20T14:00:00+00:00'));
        $this->activities->upsert($player, $day, 1100, WorkoutSource::AppleHealth, new DateTimeImmutable('2026-08-20T22:00:00+00:00'));

        $averages = $this->activities->averagesOf([$player], $day, 1);

        self::assertSame(1100, $averages[$player->toRfc4122()], 'La dernière valeur envoyée doit gagner, pas la somme des deux.');
    }

    /**
     * Un joueur sans aucune ligne vaut zéro, et n'est pas absent de la table de retour —
     * le contrat du port, voir son docblock.
     */
    public function testAPlayerWithNoDataAtAllAveragesToZeroAndStillAppearsInTheResult(): void
    {
        $player = Uuid::v7();

        $averages = $this->activities->averagesOf([$player], new DateTimeImmutable('2026-08-20'), 7);

        self::assertArrayHasKey($player->toRfc4122(), $averages);
        self::assertSame(0, $averages[$player->toRfc4122()]);
    }

    /**
     * Le lot ne mélange pas les joueurs : la journée de l'un ne bonifie pas la moyenne de
     * l'autre.
     */
    public function testTheBatchDoesNotMixPlayers(): void
    {
        $bob = $this->openAccount()->id;
        $alice = $this->openAccount('alice@grrind.app', 'Alice')->id;

        $this->activities->upsert($bob, new DateTimeImmutable('2026-08-20'), 700, WorkoutSource::AppleHealth, new DateTimeImmutable());

        $averages = $this->activities->averagesOf([$bob, $alice], new DateTimeImmutable('2026-08-20'), 7);

        self::assertSame(100, $averages[$bob->toRfc4122()]);
        self::assertSame(0, $averages[$alice->toRfc4122()]);
    }
}
