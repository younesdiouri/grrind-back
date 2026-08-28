<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\RevealRisalatHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaStatus;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeZone;
use LogicException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * La bascule hebdomadaire contre la vraie base : le tour se tire, le choix se révèle, la
 * rotation avance.
 *
 * Ce qui se démontre ici ne se démontre nulle part ailleurs. `RisalaRotationTest` prouve que
 * la **règle** est juste ; ceci prouve qu'elle est **branchée** — que le tour tiré est écrit,
 * qu'il devient une Risāla à son échéance et pas avant, et qu'il n'y en a jamais trois
 * vivantes.
 *
 * Le temps est piloté (`MockClock`, injecté au seul handler de la bascule) : trois semaines
 * de jeu tiennent en quelques millisecondes, et aucune assertion ne dépend du jour où la
 * suite tourne.
 *
 * **Les appels HTTP sont tous faits avant d'aller chercher les services** : le `KernelBrowser`
 * redémarre le noyau à chaque requête, ce qui reconstruirait l'horloge à son instant de
 * départ et ferait perdre les semaines écoulées.
 */
final class RisalatRotationTest extends ApiTestCase
{
    private MockClock $clock;
    private RevealRisalatHandler $reveal;
    private RisalaRepository $risalat;
    private GuildRepository $guilds;
    private CreditingDisciplines $crediting;
    private Guild $guild;

    public function testTheFirstTickOpensATurnForTheGuild(): void
    {
        [$guild, $members] = $this->guildOf(3);

        $this->tick();

        $turn = $this->risalat->openTurnOf($guild);
        self::assertInstanceOf(Risala::class, $turn);

        // Le tiré est un membre, et son échéance est le prochain rendez-vous — pas celui
        // qu'on vient de passer, sans quoi le tour naîtrait déjà échu.
        self::assertContains($turn->senderId()->toRfc4122(), array_map(static fn (Account $member): string => $member->id->toRfc4122(), $members));
        self::assertSame('2026-09-06 20:00:00', $turn->deadline()->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s'));
        self::assertNull($turn->discipline());
    }

    public function testTickingAgainBeforeTheDeadlineDoesNothing(): void
    {
        [$guild] = $this->guildOf(3);

        $this->tick();
        $first = $this->risalat->openTurnOf($guild);
        self::assertInstanceOf(Risala::class, $first);

        // Le battement tombe toutes les heures : la très grande majorité des exécutions ne
        // doivent rien produire. C'est aussi ce qui rend le message rejouable — pas besoin
        // d'un verrou de planificateur pour empêcher un second worker de tirer deux fois.
        $this->clock->modify('+3 days');
        $this->tick();
        $this->tick();

        self::assertSame(1, $this->countTurns());
        self::assertEquals($first->id(), $this->risalat->openTurnOf($guild)?->id());
    }

    public function testAChosenTurnBecomesALiveRisalaAtTheRevealAndNotBefore(): void
    {
        [$guild] = $this->guildOf(3);

        $this->tick();
        $this->choose(Discipline::Climbing);

        // Trois jours avant l'échéance : le choix est fait, la guilde n'en sait rien. C'est
        // toute la mécanique — le membre tiré choisit *avant* la révélation, donc sans savoir
        // ce que la semaine réserve par ailleurs.
        $this->clock->modify('+3 days');
        $this->tick();
        self::assertSame([], $this->live($guild));

        $this->clock->modify('+4 days');
        $this->tick();

        self::assertSame([Discipline::Climbing], $this->live($guild));

        // Et la semaine suivante est déjà lancée : un tour neuf, ouvert.
        self::assertInstanceOf(Risala::class, $this->risalat->openTurnOf($guild));
    }

    public function testThreeWeeksInThereAreExactlyTwoLiveRisalat(): void
    {
        [$guild] = $this->guildOf(3);

        $this->tick();
        $this->choose(Discipline::Climbing);

        $this->nextWeek();
        self::assertSame([Discipline::Climbing], $this->live($guild));
        $this->choose(Discipline::Swimming);

        $this->nextWeek();
        // Le roulement : la première vit toujours, la deuxième arrive.
        self::assertSame([Discipline::Climbing, Discipline::Swimming], $this->live($guild));
        $this->choose(Discipline::Cycling);

        $this->nextWeek();
        // La première s'éteint à la seconde exacte où la troisième naît. Jamais trois, jamais
        // un trou : c'est ce que les bornes mi-ouvertes achètent.
        self::assertSame([Discipline::Swimming, Discipline::Cycling], $this->live($guild));
    }

    public function testAnUnansweredTurnIsConsumedAndTheRotationMovesOn(): void
    {
        [$guild] = $this->guildOf(3);

        $this->tick();
        $abandoned = $this->risalat->openTurnOf($guild);
        self::assertInstanceOf(Risala::class, $abandoned);

        $this->nextWeek();

        // Consommé : pas de Risāla cette semaine, mais la rotation avance. L'inverse
        // laisserait un membre passif geler le cycle pour toute la guilde.
        self::assertSame(RisalaStatus::Missed, $this->refresh($abandoned)->status());
        self::assertSame([], $this->live($guild));

        $next = $this->risalat->openTurnOf($guild);
        self::assertInstanceOf(Risala::class, $next);
        self::assertNotEquals($abandoned->senderId(), $next->senderId());
    }

    public function testNobodyIsDrawnTwiceBeforeEveryoneHasSent(): void
    {
        [$guild, $members] = $this->guildOf(3);

        $senders = [];

        for ($week = 0; $week < 3; ++$week) {
            $this->tick();
            $turn = $this->risalat->openTurnOf($guild);
            self::assertInstanceOf(Risala::class, $turn);
            $senders[] = $turn->senderId()->toRfc4122();
            $this->clock->modify('+1 week');
        }

        // Trois semaines, trois expéditeurs différents — quel que soit le tirage. C'est la
        // seule chose que la rotation garantit, et c'est celle qui compte : sans elle, un vrai
        // hasard produirait sans faute quelqu'un qui envoie trois fois avant que le dernier
        // ait envoyé une seule.
        self::assertCount(3, array_unique($senders));
        self::assertCount(3, $members);

        // Le quatrième tour rouvre le cycle : tout le monde redevient tirable.
        $this->tick();
        self::assertSame(1, $this->risalat->openTurnOf($guild)?->cycle());
    }

    public function testAGuildOfOneDrawsNothing(): void
    {
        [$guild] = $this->guildOf(1);

        $this->tick();

        // Un défi qu'on s'envoie à soi-même n'est pas un défi. La guilde rejoint la rotation à
        // la bascule qui suit l'arrivée de son deuxième membre.
        self::assertNull($this->risalat->openTurnOf($guild));
        self::assertSame(0, $this->countTurns());
    }

    /**
     * Monte une guilde de `$size` membres, puis va chercher les services — dans cet ordre,
     * voir le docblock de la classe.
     *
     * @return array{Guild, list<Account>}
     */
    private function guildOf(int $size): array
    {
        $founder = $this->openAccount();
        $response = $this->post('/api/guilds', ['name' => 'Les Increvables'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);

        $members = [$founder];

        for ($i = 1; $i < $size; ++$i) {
            $code = self::decode($this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers))['code'];
            self::assertIsString($code);

            $member = $this->openAccount(\sprintf('membre%d@grrind.app', $i), \sprintf('Membre %d', $i));
            self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());

            $members[] = $member;
        }

        $this->clock = self::service(MockClock::class);
        $this->reveal = self::service(RevealRisalatHandler::class);
        $this->risalat = self::service(RisalaRepository::class);
        $this->guilds = self::service(GuildRepository::class);
        $this->crediting = self::service(CreditingDisciplines::class);

        $guild = $this->guilds->ofId(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);

        return [$this->guild = $guild, $members];
    }

    private function tick(): void
    {
        ($this->reveal)(new \App\Community\Application\RevealRisalat());
    }

    /** Une semaine de jeu : on avance jusqu'au rendez-vous suivant, et il tombe. */
    private function nextWeek(): void
    {
        $this->clock->modify('+1 week');
        $this->tick();
    }

    private function choose(Discipline $discipline): void
    {
        $guild = $this->guild;

        $turn = $this->risalat->openTurnOf($guild);
        self::assertInstanceOf(Risala::class, $turn);

        $turn->choose(
            $discipline,
            $this->crediting,
            array_map(static fn (Risala $live): Discipline => $live->discipline() ?? throw new LogicException('Une Risāla vivante porte toujours sa discipline.'), $this->risalat->liveIn($guild, $this->clock->now())),
            $this->clock->now(),
        );

        $this->risalat->commit();
    }

    /**
     * Les disciplines vivantes, dans l'ordre de révélation — c'est aussi celui dans lequel
     * elles s'éteindront.
     *
     * @return list<Discipline>
     */
    private function live(Guild $guild): array
    {
        return array_map(
            static fn (Risala $risala): Discipline => $risala->discipline() ?? throw new LogicException('Une Risāla vivante porte toujours sa discipline.'),
            $this->risalat->liveIn($guild, $this->clock->now()),
        );
    }

    private function refresh(Risala $risala): Risala
    {
        $refreshed = $this->risalat->find($risala->id());
        self::assertInstanceOf(Risala::class, $refreshed);

        return $refreshed;
    }

    private function countTurns(): int
    {
        return $this->risalat->count([]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $service
     *
     * @return T
     */
    private static function service(string $service): object
    {
        $instance = self::getContainer()->get($service);
        self::assertInstanceOf($service, $instance);

        return $instance;
    }
}
