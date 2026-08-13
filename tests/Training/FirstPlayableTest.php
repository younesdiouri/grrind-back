<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Le premier jouable. Tant que ce test n'existe pas, on a des modules, pas un jeu.
 *
 * Une seule journée d'un seul joueur, par les vraies routes et de bout en bout :
 * inscription, trois séances, et l'état de progression qui en découle. Rien n'est posé dans
 * le conteneur, rien n'est simulé — pas même l'horloge, dont c'est le stockage qu'on recule
 * et non le comportement qu'on contourne (voir {@see Workouts}).
 *
 * **Il tourne sur l'équilibrage réel**, `config/game/v1/`, et les montants sont écrits en
 * dur. Une fixture de test dédiée avait été envisagée au ticket et écartée : le premier
 * jouable se prouve sur le vrai jeu, sinon il prouve qu'un jeu de test fonctionne. Le prix
 * est assumé — un rééquilibrage fait échouer ce test, et c'est exactement ce qu'on veut :
 * il oblige à relire le scénario que le nouvel équilibrage produit.
 *
 * Ce que le scénario démontre et qu'aucun test isolé ne dit :
 *
 * - les **rendements décroissants** se déclenchent d'eux-mêmes au fil de la journée, et se
 *   lisent dans le breakdown — 90 XP, puis 27, puis 13 pour le même sport ;
 * - un **niveau se franchit** au milieu de la journée, sans que rien ne l'ait provoqué ;
 * - le **rejeu** d'une complétion rend le même `RewardSummary` sans rien réécrire ;
 * - `GET /api/progression` **raconte la même histoire** que la somme des récompenses.
 */
final class FirstPlayableTest extends ApiTestCase
{
    use Workouts;

    private const int HOUR = 3600;
    private const int HALF_HOUR = 1800;

    /** Une heure de course à 90 XP/h, sans un seul rendement décroissant : la tranche
     * pleine va jusqu'à 60 minutes de journée. */
    private const int FIRST = 90;

    /** La demi-heure suivante tombe dans la tranche à 60 % : 1 800 s retenues à 60 %
     * font 1 080 s, soit 27 XP là où le socle en annonçait 45. */
    private const int SECOND = 27;

    /** Et la suivante dans la tranche à 30 % : 540 s retenues, 13 XP. */
    private const int THIRD = 13;

    /**
     * Un point de tolérance sur les séances qui en suivent une autre, et **seulement**
     * sur celles-là.
     *
     * Le serveur date la clôture lui-même : la durée mesurée vaut la durée simulée plus le
     * temps réel écoulé entre les deux requêtes, une seconde ou deux sous une CI chargée.
     * Cette dérive entre au ledger, donc dans la charge du jour, et **déplace la frontière
     * entre deux tranches** : une seconde de plus sur la première heure fait passer la
     * deuxième séance de 27 à 26.
     *
     * On ne fige donc pas ces montants-là au point près. Ce que le test affirme reste
     * entier : la décroissance a lieu, elle se lit dans le breakdown, et elle est stricte.
     * L'arithmétique exacte, c'est `XpCalculatorTest` qui la démontre par table de cas,
     * sans horloge ni base.
     */
    private const int DRIFT = 1;

    public function testADayOfTrainingFromRegistrationToProgression(): void
    {
        // 1. Un compte tout neuf, par la vraie route d'inscription : le jeton qui suit est
        //    celui que le firewall accepte, pas un utilisateur posé dans le conteneur.
        $bob = $this->openAccount();

        // 2. Une heure de course. Le socle seul, aucun modificateur actif, aucun rabot.
        $first = $this->runSession($bob, 'RUNNING', self::HOUR);

        self::assertSame(self::FIRST, self::awardedIn($first));
        self::assertSame([['source' => 'BASE', 'amount' => self::FIRST]], self::breakdownIn($first));
        self::assertSame([1, 1, []], self::levelStoryIn($first));

        // Le premier titre du jeu tombe à la première séance : c'est ce qui donne au joueur
        // quelque chose à regarder avant même d'avoir un niveau.
        self::assertSame(['first_steps'], self::titleIdsIn($first));

        // 3. Une demi-heure de plus, dans la même journée. Le joueur n'a rien changé à sa
        //    pratique et pourtant le breakdown ne dit plus la même chose : la ligne
        //    négative *montre* ce qui a été rogné, au lieu de livrer un total amaigri.
        $second = $this->runSession($bob, 'RUNNING', self::HALF_HOUR);

        self::assertEqualsWithDelta(self::SECOND, self::awardedIn($second), self::DRIFT);

        // Le socle annonçait 45 ; la ligne négative dit ce qui a été rogné, et la somme des
        // deux *est* le montant accordé. C'est la forme qui compte ici, pas le point près.
        $lines = self::breakdownIn($second);
        self::assertSame(['BASE', 'DIMINISHING'], array_column($lines, 'source'));
        self::assertSame(45, $lines[0]['amount']);
        self::assertLessThan(0, $lines[1]['amount']);
        self::assertSame(self::awardedIn($second), array_sum(array_column($lines, 'amount')));

        // La même demi-heure vaut nettement moins que la première heure, à la minute près
        // de pratique. C'est ça, la mécanique.
        self::assertLessThan(self::awardedIn($first), self::awardedIn($second));

        // Et c'est cette séance-là qui fait basculer le niveau : 90 + 27 = 117, au-dessus
        // du seuil de 100. Le joueur ne l'a pas cherché, il a continué.
        self::assertSame([1, 2, [2]], self::levelStoryIn($second));
        self::assertSame(1, self::levelIn($second)['skillPointsGranted']);

        // 4. Une troisième demi-heure, dans la tranche à 30 %. La mécanique se ressent sans
        //    jamais refuser une séance : elle compte toujours, elle rapporte moins.
        $third = $this->runSession($bob, 'RUNNING', self::HALF_HOUR);

        self::assertEqualsWithDelta(self::THIRD, self::awardedIn($third), self::DRIFT);
        self::assertLessThan(self::awardedIn($second), self::awardedIn($third));
        self::assertSame([2, 2, []], self::levelStoryIn($third));

        // 5. L'état du joueur raconte la même chose que la somme de ses récompenses. C'est
        //    ce que le client lira à la réouverture de l'app, sans rejouer quoi que ce soit.
        //    L'égalité est **exacte** : elle porte sur ce qui a été observé, pas sur ce qui
        //    était prévu, et c'est précisément ce qu'on veut prouver ici.
        $progression = self::decode($this->get('/api/progression', $bob->headers));

        self::assertSame(
            self::awardedIn($first) + self::awardedIn($second) + self::awardedIn($third),
            $progression['totalXp'],
        );
        self::assertSame(2, $progression['level']);
        self::assertSame(['earned' => 1, 'available' => 1], $progression['skillPoints']);

        $unlocked = $progression['unlockedTitles'];
        self::assertIsArray($unlocked);
        self::assertCount(1, $unlocked);

        // 6. Et l'historique porte les trois séances, toutes closes.
        $history = self::decode($this->get('/api/training/sessions', $bob->headers));
        $sessions = $history['sessions'];
        self::assertIsArray($sessions);
        self::assertCount(3, $sessions);
    }

    /**
     * Le rejeu du client mobile, sur la requête qui compte : la complétion. Même clé, même
     * `RewardSummary` à l'octet près, et **aucune écriture supplémentaire** — ni au ledger,
     * ni au snapshot.
     */
    public function testReplayingACompletionChangesNothing(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob, 'RUNNING');
        $this->ageSession($session, self::HOUR);

        $path = \sprintf('/api/training/sessions/%s/complete', $session);
        $key = ['Idempotency-Key' => Uuid::v4()->toRfc4122()];

        $first = $this->post($path, [], $bob->headers + $key);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());

        $replayed = $this->post($path, [], $bob->headers + $key);

        self::assertSame($first->getContent(), $replayed->getContent());
        self::assertSame('true', $replayed->headers->get(IdempotencyListener::REPLAY_HEADER));

        // La preuve que rien n'a été rejoué : une seconde exécution aurait buté sur
        // `WorkoutNotActive` et rendu un 409, jamais la réponse d'origine.
        self::assertSame(1, $this->rowsIn('xp_transaction'));
        self::assertSame(self::FIRST, $this->totalXpOf($bob));
    }

    /**
     * Une séance dans une autre discipline le même jour n'échappe pas aux rendements
     * décroissants : ils portent sur le **temps cumulé**, toutes disciplines confondues.
     * C'est ce qui les rend impossibles à contourner en alternant les sports.
     */
    public function testTheDiminishingReturnsFollowThePlayerNotTheDiscipline(): void
    {
        $bob = $this->openAccount();

        $this->runSession($bob, 'RUNNING', self::HOUR);
        $cycling = $this->runSession($bob, 'CYCLING', self::HALF_HOUR);

        // 1 800 s à 60 % font 1 080 s retenues, à 70 XP/h : 21 XP au lieu de 35.
        self::assertEqualsWithDelta(21, self::awardedIn($cycling), self::DRIFT);

        $lines = self::breakdownIn($cycling);
        self::assertSame(['BASE', 'DIMINISHING'], array_column($lines, 'source'));
        self::assertSame(35, $lines[0]['amount']);
        self::assertLessThan(0, $lines[1]['amount']);
    }

    /**
     * Une séance close, cooldown purgé, et la récompense qu'elle a rendue.
     *
     * @return array<string, mixed>
     */
    private function runSession(Account $account, string $discipline, int $durationSeconds): array
    {
        $id = $this->startSession($account, $discipline);
        $this->ageSession($id, $durationSeconds);

        $response = $this->completeSession($account, $id);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // Reculer la séance close purge son cooldown sans toucher au ledger : la journée du
        // joueur, elle, se compte sur la date des écritures d'XP.
        $this->ageSession($id, self::HOUR);

        /** @var array<string, mixed> $summary */
        $summary = self::decode($response);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function awardedIn(array $summary): int
    {
        $xp = $summary['xp'];
        self::assertIsArray($xp);
        self::assertIsInt($xp['awarded']);

        return $xp['awarded'];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return list<array{source: string, amount: int}>
     */
    private static function breakdownIn(array $summary): array
    {
        $xp = $summary['xp'];
        self::assertIsArray($xp);
        self::assertIsArray($xp['breakdown']);

        $lines = [];

        foreach ($xp['breakdown'] as $line) {
            self::assertIsArray($line);
            self::assertIsString($line['source']);
            self::assertIsInt($line['amount']);

            $lines[] = ['source' => $line['source'], 'amount' => $line['amount']];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private static function levelIn(array $summary): array
    {
        $level = $summary['level'];
        self::assertIsArray($level);

        /** @var array<string, mixed> $level */
        return $level;
    }

    /**
     * D'où le joueur part, où il arrive, et ce qu'il a franchi — les trois nombres que le
     * client anime.
     *
     * @param array<string, mixed> $summary
     *
     * @return array<mixed>
     */
    private static function levelStoryIn(array $summary): array
    {
        $level = self::levelIn($summary);

        return [$level['before'], $level['after'], $level['reached']];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return list<mixed>
     */
    private static function titleIdsIn(array $summary): array
    {
        $titles = $summary['titlesUnlocked'];
        self::assertIsArray($titles);

        return array_values(array_map(
            static function (mixed $title): mixed {
                self::assertIsArray($title);

                return $title['id'];
            },
            $titles,
        ));
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function totalXpOf(Account $account): int
    {
        $total = $this->connection()->fetchOne(
            'SELECT total_xp FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        );
        self::assertIsNumeric($total);

        return (int) $total;
    }
}
