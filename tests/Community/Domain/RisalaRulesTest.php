<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\RisalaRules;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La grille hebdomadaire : quand la semaine bascule, et jusqu'à quand une Risāla vit.
 *
 * Le fuseau est celui de la *semaine de jeu*, pas celui d'un joueur — c'est la seule
 * exception du projet à « toute date de jeu se calcule dans le fuseau de celui qu'elle
 * concerne », et elle est assumée : une révélation est simultanée ou ce n'est pas un
 * rendez-vous.
 */
final class RisalaRulesTest extends TestCase
{
    public function testTheNextRevealIsTheComingSundayEvening(): void
    {
        // Un mercredi matin à Paris.
        $next = self::rules()->nextRevealAfter(new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Europe/Paris')));

        self::assertSame('2026-08-30 20:00:00 Europe/Paris', $next->format('Y-m-d H:i:s e'));
    }

    public function testTheInstantIsReadInTheGameTimezoneAndNotTheCallersOne(): void
    {
        // Le même instant, exprimé à Tokyo : c'est déjà mercredi soir là-bas. La grille ne
        // bouge pas pour autant — sinon deux membres d'une même guilde n'auraient pas la
        // même échéance, et « avant dimanche 20h » ne voudrait plus rien dire.
        $next = self::rules()->nextRevealAfter(new DateTimeImmutable('2026-08-26 16:00:00', new DateTimeZone('Asia/Tokyo')));

        self::assertSame('2026-08-30 20:00:00 Europe/Paris', $next->format('Y-m-d H:i:s e'));
    }

    public function testAtTheRevealItselfTheNextOneIsAWeekLater(): void
    {
        $reveal = new DateTimeImmutable('2026-08-30 20:00:00', new DateTimeZone('Europe/Paris'));

        // Strictement après, et c'est ce qui fait tourner la roue : la bascule s'exécute *à*
        // l'instant du rendez-vous et demande depuis là quand est le suivant. Une comparaison
        // large rendrait le rendez-vous courant, donc une échéance déjà échue, donc un tour
        // scellé `MISSED` dans l'heure qui suit sa naissance.
        self::assertSame('2026-09-06 20:00:00', self::rules()->nextRevealAfter($reveal)->format('Y-m-d H:i:s'));
    }

    public function testASecondBeforeTheRevealTheNextOneIsStillTonight(): void
    {
        $almost = new DateTimeImmutable('2026-08-30 19:59:59', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-08-30 20:00:00', self::rules()->nextRevealAfter($almost)->format('Y-m-d H:i:s'));
    }

    public function testTheRevealKeepsItsWallClockHourAcrossADaylightSavingChange(): void
    {
        // Le changement d'heure français de l'automne 2026 tombe dans la nuit du 24 au
        // 25 octobre. Une addition de secondes décalerait la révélation d'une heure ; le
        // calcul passe par le calendrier, donc 20h reste 20h.
        $before = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-10-25 20:00:00 +01:00', self::rules()->nextRevealAfter($before)->format('Y-m-d H:i:s P'));
    }

    public function testARisalaExpiresExactlyWhenTheOneOfTwoWeeksLaterIsRevealed(): void
    {
        $revealedAt = new DateTimeImmutable('2026-08-30 20:00:00', new DateTimeZone('Europe/Paris'));

        // Le même instant que la révélation de la semaine N+2, à la seconde près. Un décalage
        // ferait apparaître une troisième Risāla vivante, ou un trou d'une seconde — les deux
        // seraient invisibles en test et visibles en production.
        self::assertEquals(
            self::rules()->nextRevealAfter(new DateTimeImmutable('2026-09-13 19:00:00', new DateTimeZone('Europe/Paris'))),
            self::rules()->expiryOf($revealedAt),
        );
    }

    public function testARisalaOfASingleWeekIsRefused(): void
    {
        // Elle expirerait à l'instant où la suivante est révélée : le roulement
        // disparaîtrait, et avec lui les quinze jours pour caler la séance.
        $this->expectException(InvalidArgumentException::class);

        new RisalaRules(1, 7, 20, 'Europe/Paris');
    }

    public function testAnInventedTimezoneStopsTheBoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RisalaRules(2, 7, 20, 'Europe/Atlantide');
    }

    public function testAWeekdayOutsideTheIsoRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RisalaRules(2, 8, 20, 'Europe/Paris');
    }

    private static function rules(): RisalaRules
    {
        return new RisalaRules(2, 7, 20, 'Europe/Paris');
    }
}
