<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrainingSessions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le contrat le plus coûteux à casser du produit : ce que le client reçoit quand le joueur
 * appuie sur « terminer ».
 *
 * **L'ordre des clés est vérifié, et ce n'est pas du zèle.** Le client joue le payload de
 * haut en bas — la séance se referme, la barre se remplit ligne à ligne, le niveau bascule,
 * le titre tombe. Un champ déplacé change la mise en scène sans qu'aucune autre assertion
 * ne bronche ; c'est exactement le genre de régression qu'on ne voit qu'à l'écran.
 *
 * Les montants sont écrits en dur, tirés de `config/game/v1/`. Un test qui relirait
 * l'équilibrage qu'il vérifie ne vérifierait rien : un rééquilibrage doit faire échouer
 * cette suite et forcer à relire ce qu'il change.
 */
final class RewardSummaryTest extends ApiTestCase
{
    use TrainingSessions;

    /**
     * Deux heures de natation, la séance qui traverse toutes les tranches de rendements
     * décroissants : 3 600 s à 100 %, 1 800 s à 60 %, 1 800 s à 30 % — soit 5 220 s
     * retenues sur 7 200.
     */
    private const int ELAPSED = 7200;

    /** 7 200 s × 100 XP/h ÷ 3 600. */
    private const int FULL_BASE = 200;

    /** 5 220 s retenues × 100 XP/h ÷ 3 600. */
    private const int AWARDED = 145;

    public function testTheSummaryIsOrderedLikeTheAnimation(): void
    {
        $bob = $this->openAccount();

        $body = $this->completeTwoHoursOfSwimming($bob);

        self::assertSame(
            ['session', 'xp', 'level', 'titlesUnlocked', 'loot', 'streak', 'unlockableNodes', 'rulesetVersion'],
            array_keys($body),
        );
    }

    /**
     * Le détail ligne à ligne, dans l'ordre du calcul. La ligne négative est le cœur du
     * geste : le breakdown **montre ce qui a été rogné** au lieu de livrer un total
     * amaigri sans explication, sinon une mécanique se lit comme une punition.
     */
    public function testTheBreakdownShowsWhatWasTrimmed(): void
    {
        $bob = $this->openAccount();

        $xp = $this->completeTwoHoursOfSwimming($bob)['xp'];
        self::assertIsArray($xp);

        self::assertSame(self::AWARDED, $xp['awarded']);
        self::assertSame(
            [
                ['source' => 'BASE', 'amount' => self::FULL_BASE],
                ['source' => 'DIMINISHING', 'amount' => self::AWARDED - self::FULL_BASE],
            ],
            $xp['breakdown'],
        );
    }

    /**
     * Le passage de niveau se donne des deux côtés, plus la liste de ce qui a été franchi :
     * en gagner plusieurs d'un coup est un cas normal, et le client les anime tous.
     */
    public function testTheLevelUpCarriesItsBeforeAndAfter(): void
    {
        $bob = $this->openAccount();

        $level = $this->completeTwoHoursOfSwimming($bob)['level'];
        self::assertIsArray($level);

        self::assertSame(
            [
                'before' => 1,
                'after' => 2,
                'reached' => [2],
                'totalXp' => self::AWARDED,
                // 145 − 100, le seuil du niveau 2.
                'xpIntoLevel' => 45,
                // 260 − 145 : ce qu'il **reste** à gagner, pas la largeur du palier.
                'xpToNextLevel' => 115,
                'skillPointsGranted' => 1,
            ],
            $level,
        );
    }

    /**
     * Une séance sans franchissement ne ment pas : `before` vaut `after` et la liste est
     * vide. Le client n'a alors rien à jouer, et il le sait sans comparer deux nombres.
     */
    public function testASessionWithoutALevelUpSaysSoPlainly(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);

        $level = self::decode($this->completeSession($bob, $session))['level'];
        self::assertIsArray($level);

        self::assertSame([1, 1, [], 0], [
            $level['before'],
            $level['after'],
            $level['reached'],
            $level['skillPointsGranted'],
        ]);
    }

    /**
     * Le titre arrive **traduit et barre pleine**, dans la forme unique que servent déjà
     * `GET /api/me` et `GET /api/titles` : un seul type à décoder, un seul composant à
     * dessiner. C'est ça, « un seul aller-retour ».
     */
    public function testAnUnlockedTitleArrivesReadyToDisplay(): void
    {
        $bob = $this->openAccount();

        $titles = $this->completeTwoHoursOfSwimming($bob)['titlesUnlocked'];
        self::assertIsArray($titles);
        self::assertCount(1, $titles);

        $first = $titles[0];
        self::assertIsArray($first);
        self::assertSame('first_steps', $first['id']);
        self::assertSame('First Steps', $first['name']);
        self::assertTrue($first['unlocked']);
        self::assertIsString($first['unlockedAt']);
        self::assertSame(['current' => 1, 'target' => 1, 'unit' => 'SESSIONS'], $first['progress']);
    }

    /**
     * Les champs des Lots 5 à 7 sont **présents et vides**. Les ajouter plus tard
     * obligerait un client déjà déployé à traiter des clés qui apparaissent, donc à les
     * rendre optionnelles pour toujours.
     */
    public function testTheSlotsOfTheComingLotsAreAlreadyThere(): void
    {
        $bob = $this->openAccount();

        $body = $this->completeTwoHoursOfSwimming($bob);

        self::assertSame([], $body['loot']);
        self::assertNull($body['streak']);
        self::assertSame([], $body['unlockableNodes']);

        // Sous quel équilibrage ces montants ont été accordés : c'est ce qui rend une
        // capture d'écran exploitable dans un rapport de bug.
        self::assertIsString($body['rulesetVersion']);
        self::assertNotSame('', $body['rulesetVersion']);
    }

    /** La séance close reste décodable telle quelle, sous sa propre clé. */
    public function testTheClosedSessionTravelsWithTheReward(): void
    {
        $bob = $this->openAccount();

        $session = $this->completeTwoHoursOfSwimming($bob)['session'];
        self::assertIsArray($session);

        self::assertSame('COMPLETED', $session['status']);
        self::assertSame('SWIMMING', $session['discipline']);

        // À la seconde près, et pas à la seconde exacte : `ageSession` recule la séance,
        // mais c'est le serveur qui date la clôture. Le temps réel qui passe entre les
        // deux requêtes s'ajoute à la durée, et il n'est pas nul sous une CI chargée.
        //
        // Les montants, eux, restent écrits en dur : au-delà de la dernière tranche de
        // rendements décroissants, une seconde de plus est retenue à 0 % et ne change rien
        // à ce qui est accordé. C'est ce qui permet à cette suite de rester exacte là où
        // ça compte.
        self::assertEqualsWithDelta(self::ELAPSED, $session['durationSeconds'], 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeTwoHoursOfSwimming(Account $account): array
    {
        $session = $this->startSession($account, 'SWIMMING');
        $this->ageSession($session, self::ELAPSED);

        $response = $this->completeSession($account, $session);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $body */
        $body = self::decode($response);

        return $body;
    }
}
