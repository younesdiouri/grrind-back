<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Component\Uid\Uuid;

/**
 * L'écriture d'XP contre la vraie base : ledger et snapshot dans une seule transaction.
 *
 * C'est le squelette de la transaction de complétion (#21). Ce qui se démontre ici ne se
 * démontre pas en mémoire — que le snapshot est **dérivé** du ledger et non incrémenté, que
 * la ligne se crée d'elle-même au premier crédit, et que le verrou refuse de s'exécuter hors
 * transaction, où il ne verrouillerait rien.
 *
 * Le compte vient de la vraie route d'inscription : `DailyLoadProvider` a besoin d'un fuseau,
 * et il le lit à travers le port `PlayerTimezones`.
 */
final class GrantXpTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;
    private XpTransactionRepository $ledger;
    private ProgressionSnapshotRepository $snapshots;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();

        $grantXp = $container->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;

        $ledger = $container->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;

        $snapshots = $container->get(ProgressionSnapshotRepository::class);
        self::assertInstanceOf(ProgressionSnapshotRepository::class, $snapshots);
        $this->snapshots = $snapshots;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testCreditsTheLedgerAndProjectsTheSnapshot(): void
    {
        $player = $this->openAccount()->id;
        $sessionId = Uuid::v7();

        $granted = ($this->grantXp)(new GrantXp($player, $sessionId, Discipline::Running, 3600));

        // Une heure de course rapporte quelque chose, et ce quelque chose est au ledger,
        // attribué à la séance qui l'a produit.
        self::assertGreaterThan(0, $granted->award->amount());
        $credit = $this->ledger->recordedFor($sessionId, XpReason::SessionCompleted);
        self::assertInstanceOf(XpTransaction::class, $credit);
        self::assertSame($granted->award->amount(), $credit->amount());

        // Et le snapshot dit exactement ce que la courbe dit du total au ledger. Aucune de
        // ses colonnes n'est une information de plus : elles se redéduisent toutes.
        $total = $this->ledger->totalOf($player);
        self::assertSame($granted->award->amount(), $total);
        self::assertSnapshotMatchesTheCurve($granted->snapshot, $total);

        // La ligne a survécu au commit, pas seulement à l'unité de travail.
        $this->entityManager->clear();
        $reloaded = $this->snapshots->ofPlayer($player);
        self::assertInstanceOf(ProgressionSnapshot::class, $reloaded);
        self::assertSame($total, $reloaded->totalXp());
    }

    public function testTheFirstCreditCreatesTheLineAndTheNextOnesReuseIt(): void
    {
        $player = $this->openAccount()->id;

        // Aucune inscription ne crée de ligne de progression : c'est le premier crédit qui
        // la pose, sans quoi `Identity` devrait connaître `Progression`.
        self::assertNull($this->snapshots->ofPlayer($player));

        ($this->grantXp)(new GrantXp($player, Uuid::v7(), Discipline::Running, 1800));
        ($this->grantXp)(new GrantXp($player, Uuid::v7(), Discipline::Cycling, 1800));

        self::assertCount(1, $this->snapshots->findAll());
        self::assertSame($this->ledger->totalOf($player), $this->snapshots->ofPlayer($player)?->totalXp());
    }

    public function testTheSnapshotIsRecomputedFromTheLedgerRatherThanIncremented(): void
    {
        $player = $this->openAccount()->id;

        // Une écriture que le snapshot n'a jamais vue passer : un import, une correction, ou
        // simplement une reprise après incident. Datée d'avant-hier pour ne peser ni sur les
        // rendements décroissants ni sur le plafond du jour.
        $this->seedLedger($player, 1_000, new DateTimeImmutable('-2 days'));

        $granted = ($this->grantXp)(new GrantXp($player, Uuid::v7(), Discipline::Running, 3600));

        // Le handler relit la somme au lieu d'ajouter au compteur : l'écart se résorbe tout
        // seul au crédit suivant, là où un `+=` l'aurait entériné pour toujours.
        self::assertSame(1_000 + $granted->award->amount(), $granted->snapshot->totalXp());
        self::assertSnapshotMatchesTheCurve($granted->snapshot, $this->ledger->totalOf($player));
    }

    public function testAnnouncesEveryLevelCrossedAndTheSkillPointsTheyGrant(): void
    {
        $player = $this->openAccount()->id;

        // De quoi dépasser le haut de la courbe, quel que soit l'équilibrage livré : ce qui
        // est vérifié est la mécanique d'annonce, pas la valeur des seuils.
        $this->seedLedger($player, 1_000_000, new DateTimeImmutable('-2 days'));

        $granted = ($this->grantXp)(new GrantXp($player, Uuid::v7(), Discipline::Running, 1800));

        // Tous les niveaux, dans l'ordre, du deuxième au dernier — le client les anime un
        // par un, un booléen lui en ferait avaler quarante-huit en silence.
        self::assertSame(range(2, self::curve()->maxLevel()), $granted->levelsReached);
        self::assertSame($granted->snapshot->earnedSkillPoints(), $granted->skillPointsGranted);
        self::assertNull($granted->snapshot->xpToNextLevel());
    }

    public function testASessionAlreadyCreditedCannotBeCreditedAgain(): void
    {
        $player = $this->openAccount()->id;
        $sessionId = Uuid::v7();

        ($this->grantXp)(new GrantXp($player, $sessionId, Discipline::Running, 1800));

        // Un client mobile rejoue ses requêtes. La transaction entière est annulée, donc le
        // snapshot ne bouge pas non plus : c'est ce que le `wrapInTransaction` achète.
        $this->expectException(UniqueConstraintViolationException::class);

        ($this->grantXp)(new GrantXp($player, $sessionId, Discipline::Running, 1800));
    }

    public function testTheLockRefusesToRunOutsideATransaction(): void
    {
        // Un verrou pessimiste posé hors transaction se relâche à la requête suivante : il
        // ne sérialise rien, et deux complétions simultanées s'écraseraient en silence.
        // Doctrine refuse plutôt que de faire semblant, et le handler ne l'appelle que sous
        // `wrapInTransaction`.
        $this->expectException(TransactionRequiredException::class);

        $this->snapshots->lockFor(Uuid::v7(), self::curve());
    }

    private static function assertSnapshotMatchesTheCurve(ProgressionSnapshot $snapshot, int $total): void
    {
        $standing = self::curve()->standingAt($total);

        self::assertSame($total, $snapshot->totalXp());
        self::assertSame($standing->level, $snapshot->level());
        self::assertSame($standing->xpIntoLevel, $snapshot->xpIntoLevel());
        self::assertSame($standing->xpToNextLevel, $snapshot->xpToNextLevel());
        self::assertSame($standing->earnedSkillPoints, $snapshot->earnedSkillPoints());
    }

    /** Une écriture au ledger qui ne passe pas par le handler, donc que le snapshot ignore. */
    private function seedLedger(Uuid $player, int $amount, DateTimeImmutable $at): void
    {
        $this->ledger->add(XpTransaction::creditFor(
            $player,
            Uuid::v7(),
            Discipline::Running,
            0,
            new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $amount)), 'v1-000000000000'),
            $at,
        ));
        $this->ledger->commit();
    }

    /**
     * La courbe livrée, construite depuis son paramètre : rien ne consomme `LevelCurve` en
     * dehors du handler, donc le conteneur ne l'expose pas. Même geste que pour `XpRates`.
     */
    private static function curve(): LevelCurve
    {
        $levels = self::getContainer()->getParameter('game.levels.levels');
        self::assertIsArray($levels);

        /** @var list<array{level: int, total_xp: int, skill_points: int}> $levels */
        return new LevelCurve($levels);
    }
}
