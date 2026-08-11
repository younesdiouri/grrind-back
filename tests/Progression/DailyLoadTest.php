<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\DailyLoadProvider;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * La journée du joueur, de bout en bout : le fuseau vient d'un vrai compte, traverse la
 * frontière des modules par le port `PlayerTimezones`, et délimite la fenêtre du ledger.
 *
 * C'est le seul test qui prouve le câblage du port — les tests de domaine, eux, reçoivent
 * un `DailyLoad` déjà construit.
 */
final class DailyLoadTest extends ApiTestCase
{
    private DailyLoadProvider $provider;
    private XpTransactionRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = self::getContainer()->get(DailyLoadProvider::class);
        self::assertInstanceOf(DailyLoadProvider::class, $provider);
        $this->provider = $provider;

        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;
    }

    public function testCountsOnlyWhatFallsInThePlayerDay(): void
    {
        // Le compte est ouvert à Paris. 22 h 30 UTC, c'est 23 h 30 chez lui : encore le 1er.
        // 23 h 30 UTC, c'est 0 h 30 le 2 : la journée a tourné, alors que le serveur est
        // toujours le 1er.
        $player = $this->openAccount()->id;

        $this->credit($player, Discipline::Running, 45, 1800, '2026-03-01T22:30:00+00:00');
        $this->credit($player, Discipline::Running, 60, 2400, '2026-03-01T23:30:00+00:00');

        $veille = $this->provider->of($player, Discipline::Running, new DateTimeImmutable('2026-03-01T22:45:00+00:00'));
        self::assertSame(1800, $veille->secondsSoFar);
        self::assertSame(45, $veille->xpSoFarInDiscipline);

        $lendemain = $this->provider->of($player, Discipline::Running, new DateTimeImmutable('2026-03-01T23:45:00+00:00'));
        self::assertSame(2400, $lendemain->secondsSoFar);
        self::assertSame(60, $lendemain->xpSoFarInDiscipline);
    }

    public function testTheTimeIsCumulativeAcrossDisciplinesButTheXpIsNot(): void
    {
        // Les deux garde-fous ne visent pas la même chose : le volume d'entraînement
        // décroît quoi qu'on pratique, le plafond empêche de tout mettre sur la discipline
        // la mieux payée.
        $player = $this->openAccount()->id;

        $this->credit($player, Discipline::Running, 90, 3600, '2026-03-02T10:00:00+00:00');
        $this->credit($player, Discipline::Swimming, 50, 1800, '2026-03-02T12:00:00+00:00');

        $today = $this->provider->of($player, Discipline::Running, new DateTimeImmutable('2026-03-02T14:00:00+00:00'));

        self::assertSame(5400, $today->secondsSoFar);
        self::assertSame(90, $today->xpSoFarInDiscipline);
    }

    public function testAnInvalidatedSessionStopsWeighingOnTheDay(): void
    {
        $player = $this->openAccount()->id;
        $credit = $this->credit($player, Discipline::Running, 90, 3600, '2026-03-02T10:00:00+00:00');

        $this->ledger->add(XpTransaction::reversalOf($credit, new DateTimeImmutable('2026-03-02T11:00:00+00:00')));
        $this->ledger->commit();

        // Montant *et* durée s'annulent par simple somme : la séance invalidée cesse de
        // peser sur les rendements décroissants comme elle cesse de compter en XP, sans que
        // la requête ait à filtrer sur les raisons.
        $today = $this->provider->of($player, Discipline::Running, new DateTimeImmutable('2026-03-02T14:00:00+00:00'));

        self::assertSame(0, $today->secondsSoFar);
        self::assertSame(0, $today->xpSoFarInDiscipline);
    }

    public function testAPlayerWithoutHistoryStartsFromScratch(): void
    {
        $today = $this->provider->of(Uuid::v7(), Discipline::Running, new DateTimeImmutable('2026-03-02T14:00:00+00:00'));

        // Compte inconnu : le port rend UTC plutôt que de lever, et le ledger est vide.
        self::assertSame(0, $today->secondsSoFar);
        self::assertSame(0, $today->xpSoFarInDiscipline);
    }

    private function credit(Uuid $player, Discipline $discipline, int $amount, int $durationSeconds, string $at): XpTransaction
    {
        $transaction = XpTransaction::creditFor(
            $player,
            Uuid::v7(),
            $discipline,
            $durationSeconds,
            new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $amount)), 'v1-000000000000'),
            new DateTimeImmutable($at),
        );

        $this->ledger->add($transaction);
        $this->ledger->commit();

        return $transaction;
    }
}
