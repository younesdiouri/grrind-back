<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\Exception\DisciplineAlreadyChallenged;
use App\Community\Domain\Exception\DisciplineDoesNotCredit;
use App\Community\Domain\Exception\RisalaTurnIsClosed;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRotation;
use App\Community\Domain\RisalaRules;
use App\Community\Domain\RisalaStatus;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le tour et ce qu'il devient : un choix, ou rien. Tout se démontre sans base — l'agrégat ne
 * demande à l'appelant que ce qu'il ne peut pas voir lui-même (les Risālāt vivantes de sa
 * guilde, l'appartenance de son expéditeur).
 */
final class RisalaTest extends TestCase
{
    public function testAFreshTurnCarriesTheTraceOfItsDraw(): void
    {
        $members = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $rotation = new RisalaRotation($members, [], 0);

        $risala = Risala::draw(self::guild(), $rotation, 1, self::drawnAt(), self::deadline());

        // Rien n'est choisi, et c'est l'état normal d'un tour qui vient de s'ouvrir.
        self::assertSame(RisalaStatus::Drawn, $risala->status());
        self::assertNull($risala->discipline());
        self::assertSame($rotation->pool[1], $risala->senderId());
    }

    public function testTheChoiceIsRecordedAndCanBeChangedUntilTheDeadline(): void
    {
        $risala = self::openTurn();

        $risala->choose(Discipline::Climbing, self::crediting(), [], self::beforeTheDeadline());
        self::assertSame(Discipline::Climbing, $risala->discipline());

        // On change d'avis sur un sport qu'on propose aux autres, et ce revirement ne coûte
        // rien à personne tant que rien n'a été annoncé.
        $risala->choose(Discipline::Swimming, self::crediting(), [], self::beforeTheDeadline());
        self::assertSame(Discipline::Swimming, $risala->discipline());
    }

    public function testChoosingAfterTheDeadlineIsRefused(): void
    {
        $this->expectException(RisalaTurnIsClosed::class);

        self::openTurn()->choose(Discipline::Climbing, self::crediting(), [], self::deadline());
    }

    public function testADisciplineThatEarnsNothingIsRefused(): void
    {
        // `WALKING` ne rapporte plus d'XP (#181) : « +150 % » y serait +150 % de zéro, et le
        // joueur ne peut pas le deviner depuis l'écran.
        $this->expectException(DisciplineDoesNotCredit::class);

        self::openTurn()->choose(Discipline::Walking, self::crediting(), [], self::beforeTheDeadline());
    }

    public function testADisciplineAlreadyCarriedByALiveRisalaIsRefused(): void
    {
        // Les deux bonus s'additionneraient à +300 % sur le même sport, ce qui n'a jamais été
        // le barème — et surtout, proposer ce que la guilde pratique déjà depuis une semaine
        // rate l'intention de la mécanique.
        $this->expectException(DisciplineAlreadyChallenged::class);

        self::openTurn()->choose(Discipline::Climbing, self::crediting(), [Discipline::Climbing], self::beforeTheDeadline());
    }

    public function testSealingAChosenTurnRevealsTheRisalaOnTheGrid(): void
    {
        $risala = self::openTurn();
        $risala->choose(Discipline::Climbing, self::crediting(), [], self::beforeTheDeadline());

        $risala->seal(self::rules(), senderIsStillAMember: true);

        self::assertSame(RisalaStatus::Sent, $risala->status());

        // Datée par sa grille et non par l'horloge de la bascule : cinq secondes de décalage
        // suffiraient à faire exister un moment à trois Risālāt vivantes chaque semaine.
        self::assertEquals(self::deadline(), $risala->revealedAt());
        self::assertEquals(self::rules()->expiryOf(self::deadline()), $risala->expiresAt());
    }

    public function testSealingATurnNobodyAnsweredConsumesItAllTheSame(): void
    {
        $risala = self::openTurn();

        $risala->seal(self::rules(), senderIsStillAMember: true);

        // Consommé : la rotation avance. L'inverse laisserait un membre passif geler le cycle
        // pour toute la guilde, semaine après semaine.
        self::assertSame(RisalaStatus::Missed, $risala->status());
        self::assertNull($risala->revealedAt());
    }

    public function testAChoiceMadeBySomeoneWhoLeftIsNotRevealed(): void
    {
        $risala = self::openTurn();
        $risala->choose(Discipline::Climbing, self::crediting(), [], self::beforeTheDeadline());

        $risala->seal(self::rules(), senderIsStillAMember: false);

        // Une Risāla est envoyée *par un membre*. La laisser partir ferait vivre le choix de
        // quelqu'un deux semaines de plus que son appartenance à la guilde.
        self::assertSame(RisalaStatus::Missed, $risala->status());
        self::assertNull($risala->discipline());
    }

    public function testSealingTwiceChangesNothing(): void
    {
        $risala = self::openTurn();
        $risala->choose(Discipline::Climbing, self::crediting(), [], self::beforeTheDeadline());
        $risala->seal(self::rules(), senderIsStillAMember: true);

        // L'outbox livre au moins une fois : la bascule sera rejouée un jour, et elle ne doit
        // pas transformer une Risāla révélée en tour manqué parce que son expéditeur est
        // parti entre-temps.
        $risala->seal(self::rules(), senderIsStillAMember: false);

        self::assertSame(RisalaStatus::Sent, $risala->status());
        self::assertSame(Discipline::Climbing, $risala->discipline());
    }

    public function testALiveRisalaCoversItsRevealButNotItsExpiry(): void
    {
        $risala = self::openTurn();
        $risala->choose(Discipline::Climbing, self::crediting(), [], self::beforeTheDeadline());
        $risala->seal(self::rules(), senderIsStillAMember: true);

        $expiry = self::rules()->expiryOf(self::deadline());

        // Bornes mi-ouvertes : celle de la semaine N−2 s'éteint à la seconde où celle de N
        // naît, donc il y en a exactement deux et jamais un instant à trois.
        self::assertTrue($risala->isLiveAt(self::deadline()));
        self::assertTrue($risala->isLiveAt($expiry->modify('-1 second')));
        self::assertFalse($risala->isLiveAt($expiry));
        self::assertFalse($risala->isLiveAt(self::deadline()->modify('-1 second')));
    }

    public function testATurnIsDueOnlyOnceItsDeadlineIsReached(): void
    {
        $risala = self::openTurn();

        self::assertFalse($risala->isDueAt(self::beforeTheDeadline()));
        self::assertTrue($risala->isDueAt(self::deadline()));
    }

    private static function openTurn(): Risala
    {
        return Risala::draw(self::guild(), new RisalaRotation([Uuid::v7(), Uuid::v7()], [], 0), 0, self::drawnAt(), self::deadline());
    }

    private static function guild(): Guild
    {
        return Guild::found('Les Increvables', Uuid::v7(), self::drawnAt());
    }

    private static function rules(): RisalaRules
    {
        return new RisalaRules(2, 7, 20, 'Europe/Paris');
    }

    /** Toutes les disciplines créditent, sauf `WALKING` — l'état réel de `xp.yaml`. */
    private static function crediting(): CreditingDisciplines
    {
        return new CreditingDisciplines(array_map(
            static fn (Discipline $discipline): array => [
                'discipline' => $discipline->value,
                'credits_xp' => Discipline::Walking !== $discipline,
            ],
            Discipline::cases(),
        ));
    }

    private static function drawnAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-23 20:00:00', new DateTimeZone('Europe/Paris'));
    }

    private static function deadline(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-30 20:00:00', new DateTimeZone('Europe/Paris'));
    }

    private static function beforeTheDeadline(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-28 10:00:00', new DateTimeZone('Europe/Paris'));
    }
}
