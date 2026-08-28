<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\RevealRisalat;
use App\Community\Application\RevealRisalatHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Les deux routes des Risālāt, contre la vraie base et le vrai firewall.
 *
 * La situation est montée par le **vrai chemin** — bascule, choix, bascule — et calée pour
 * qu'au moment des requêtes HTTP il y ait exactement une Risāla vivante et un tour ouvert
 * dont l'échéance est encore devant. C'est le régime établi du produit, pas un cas limite.
 *
 * Trois membres, donc trois personnes distinctes : celle qui a envoyé la Risāla vivante,
 * celle dont c'est le tour, et une troisième qui ne peut rien faire. Les trois points de vue
 * se lisent alors sans ambiguïté.
 */
final class RisalatRoutesTest extends ApiTestCase
{
    private const string CHALLENGED = 'CLIMBING';

    private MockClock $clock;
    private RevealRisalatHandler $reveal;
    private RisalaRepository $risalat;
    private GuildRepository $guilds;
    private CreditingDisciplines $crediting;
    private Guild $guild;

    public function testTheScreenShowsTheLiveRisalaAndTheCurrentTurn(): void
    {
        [$liveSender, $turnHolder] = $this->guildInFullSwing();

        $body = self::decode($this->get('/api/guilds/mine/risalat', $turnHolder->headers));

        self::assertIsArray($risalat = $body['risalat']);
        self::assertCount(1, $risalat);
        self::assertIsArray($risala = $risalat[0]);

        self::assertSame(self::CHALLENGED, $risala['discipline']);
        self::assertSame($liveSender->id->toRfc4122(), $risala['senderId']);

        // Résolu pour l'appelant : il la reçoit, donc +150 %.
        self::assertSame(150, $risala['bonusPercent']);

        self::assertIsArray($turn = $body['turn']);
        self::assertTrue($turn['mine']);
        self::assertNull($turn['discipline']);

        // Ce qui crédite, moins ce qui est déjà défié. `WALKING` ne rapporte plus d'XP, donc
        // une Risāla dessus ne promettrait rien.
        self::assertIsArray($choosable = $turn['choosable']);
        self::assertNotContains(self::CHALLENGED, $choosable);
        self::assertNotContains('WALKING', $choosable);
        self::assertContains('SWIMMING', $choosable);
    }

    public function testTheSenderOfALiveRisalaSeesHisOwnRateOnIt(): void
    {
        [$liveSender] = $this->guildInFullSwing();

        $risalat = self::decode($this->get('/api/guilds/mine/risalat', $liveSender->headers))['risalat'];
        self::assertIsArray($risalat);
        self::assertIsArray($risalat[0]);

        // Assez pour que proposer ne soit pas un sacrifice, pas assez pour qu'on propose le
        // sport qu'on pratique déjà.
        self::assertSame(50, $risalat[0]['bonusPercent']);
    }

    public function testTheTurnHolderChoosesAndReadsItBack(): void
    {
        [, $turnHolder] = $this->guildInFullSwing();

        $chosen = self::decode($this->choose($turnHolder, 'SWIMMING', Response::HTTP_OK));

        // La réponse est l'écran complet : après un choix, le client n'a rien à recharger.
        self::assertIsArray($turn = $chosen['turn']);
        self::assertSame('SWIMMING', $turn['discipline']);

        // Et le choix se refait tant que l'échéance n'est pas passée — c'est ce que `PUT`
        // promet, et ce que la mécanique autorise : on change d'avis sur un sport qu'on
        // propose aux autres.
        $again = self::decode($this->choose($turnHolder, 'HIKING', Response::HTTP_OK));
        self::assertIsArray($again['turn']);
        self::assertSame('HIKING', $again['turn']['discipline']);
    }

    public function testTheChoiceStaysInvisibleToTheOtherMembers(): void
    {
        [, $turnHolder, $bystander] = $this->guildInFullSwing();

        $this->choose($turnHolder, 'SWIMMING', Response::HTTP_OK);

        $turn = self::decode($this->get('/api/guilds/mine/risalat', $bystander->headers))['turn'];
        self::assertIsArray($turn);

        // Le choix se fait à l'aveugle : c'est toute la mécanique. L'annoncer d'avance
        // viderait le rendez-vous du dimanche soir de sa raison d'être.
        self::assertFalse($turn['mine']);
        self::assertNull($turn['discipline']);
        self::assertSame($turnHolder->id->toRfc4122(), $turn['senderId']);
    }

    public function testAnotherMemberCannotChooseInHisPlace(): void
    {
        [, , $bystander] = $this->guildInFullSwing();

        // 403 et non 404, contrairement au reste du module : le refus ne protège rien, la
        // requête précédente lui a déjà dit à qui appartient le tour.
        $refusal = self::decode($this->choose($bystander, 'SWIMMING', Response::HTTP_FORBIDDEN));
        self::assertSame('risala-turn-is-not-yours', self::typeOf($refusal));
    }

    public function testADisciplineThatEarnsNothingIsRefused(): void
    {
        [, $turnHolder] = $this->guildInFullSwing();

        $refusal = self::decode($this->choose($turnHolder, 'WALKING', Response::HTTP_UNPROCESSABLE_ENTITY));
        self::assertSame('discipline-does-not-credit', self::typeOf($refusal));
    }

    public function testADisciplineAlreadyChallengedIsRefused(): void
    {
        [, $turnHolder] = $this->guildInFullSwing();

        $refusal = self::decode($this->choose($turnHolder, self::CHALLENGED, Response::HTTP_UNPROCESSABLE_ENTITY));
        self::assertSame('discipline-already-challenged', self::typeOf($refusal));
    }

    public function testAnInventedDisciplineIsARequestViolation(): void
    {
        [, $turnHolder] = $this->guildInFullSwing();

        // Le Serializer refuse tout seul une valeur hors de l'énumération : rien à valider à
        // la main, donc rien à oublier de valider.
        $this->choose($turnHolder, 'QUIDDITCH', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAPlayerWithoutAGuildHasNoScreenAtAll(): void
    {
        $solo = $this->openAccount('solo@grrind.app', 'Solo');

        // Contrairement à `GET /api/guilds/mine`, qui rend `{"guild": null}` : là-bas, « je
        // n'ai pas de guilde » est une réponse que l'écran sait dessiner. Ici, l'écran
        // n'existe qu'à l'intérieur d'une guilde.
        $response = $this->get('/api/guilds/mine/risalat', $solo->headers);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('guild-not-found', self::typeOf(self::decode($response)));
    }

    public function testAGuildWithoutATurnAnswersWithAnEmptyScreen(): void
    {
        $founder = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'La solitaire'], $founder->headers);

        // Une guilde d'un seul membre ne tire personne : un défi qu'on s'envoie à soi-même
        // n'en est pas un. L'écran existe, il est simplement vide.
        $body = self::decode($this->get('/api/guilds/mine/risalat', $founder->headers));
        self::assertSame([], $body['risalat']);
        self::assertNull($body['turn']);

        $refusal = self::decode($this->choose($founder, 'SWIMMING', Response::HTTP_NOT_FOUND));
        self::assertSame('risala-turn-is-not-open', self::typeOf($refusal));
    }

    public function testTheScreenIsClosedToAnonymousCallers(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/guilds/mine/risalat')->getStatusCode());
    }

    /**
     * Une guilde de trois en régime établi : une Risāla vivante, un tour ouvert dont
     * l'échéance est encore devant, et trois personnes distinctes.
     *
     * La semaine de jeu part de **deux semaines avant maintenant** : la Risāla est alors
     * révélée il y a une semaine — donc vivante — et le tour ouvert deux bascules plus tard
     * a son échéance dans le futur, donc choisissable. Une date fixe rendrait ces deux
     * propriétés fausses au bout d'un mois.
     *
     * @return array{Account, Account, Account} l'expéditeur de la Risāla vivante, le porteur du tour, un témoin
     */
    private function guildInFullSwing(): array
    {
        $members = $this->guildOfThree();

        $this->clock->modify(new DateTimeImmutable('-2 weeks')->format('Y-m-d H:i:sP'));

        ($this->reveal)(new RevealRisalat());
        $liveSender = $this->chooseInDomain(Discipline::from(self::CHALLENGED));

        // Première bascule : la Risāla part. Le tour suivant s'ouvre dans la foulée.
        $this->advanceToTheDeadlineAndTick();

        // Seconde bascule : ce tour-là n'a pas été honoré, il est consommé, et celui qui
        // s'ouvre a son échéance après maintenant — c'est celui que les requêtes HTTP visent.
        $this->advanceToTheDeadlineAndTick();

        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);
        self::assertGreaterThan(new DateTimeImmutable(), $turn->deadline(), 'Le tour ouvert doit encore être choisissable.');

        $holder = self::among($members, $turn->senderId());
        $sender = self::among($members, $liveSender);

        $bystander = array_values(array_filter(
            $members,
            static fn (Account $member): bool => !$member->id->equals($turn->senderId()) && !$member->id->equals($liveSender),
        ));

        self::assertCount(1, $bystander, 'La rotation doit avoir désigné trois personnes distinctes.');

        return [$sender, $holder, $bystander[0]];
    }

    /**
     * @return list<Account>
     */
    private function guildOfThree(): array
    {
        $founder = $this->openAccount();
        $response = $this->post('/api/guilds', ['name' => 'Les Increvables'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);

        $members = [$founder];

        foreach (['baha' => 'Baha', 'carla' => 'Carla'] as $handle => $name) {
            $code = self::decode($this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers))['code'];
            self::assertIsString($code);

            $member = $this->openAccount($handle.'@grrind.app', $name);
            self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());

            $members[] = $member;
        }

        // Après le dernier appel HTTP : le `KernelBrowser` redémarre le noyau à chaque
        // requête, ce qui reconstruirait l'horloge à son instant de départ.
        $this->clock = self::service(MockClock::class);
        $this->reveal = self::service(RevealRisalatHandler::class);
        $this->risalat = self::service(RisalaRepository::class);
        $this->guilds = self::service(GuildRepository::class);
        $this->crediting = self::service(CreditingDisciplines::class);

        $guild = $this->guilds->ofId(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);
        $this->guild = $guild;

        return $members;
    }

    /** Le choix passe par le domaine ici : la route HTTP redémarrerait le noyau et l'horloge. */
    private function chooseInDomain(Discipline $discipline): Uuid
    {
        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);

        $turn->choose($discipline, $this->crediting, [], $this->clock->now());
        $this->risalat->commit();

        return $turn->senderId();
    }

    private function advanceToTheDeadlineAndTick(): void
    {
        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);

        $this->clock->sleep($turn->deadline()->getTimestamp() - $this->clock->now()->getTimestamp());

        ($this->reveal)(new RevealRisalat());
    }

    private function choose(Account $player, string $discipline, int $expected): Response
    {
        $response = $this->send('PUT', '/api/guilds/mine/risalat/turn', ['discipline' => $discipline], $player->headers);
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @param list<Account> $members
     */
    private static function among(array $members, Uuid $playerId): Account
    {
        foreach ($members as $member) {
            if ($member->id->equals($playerId)) {
                return $member;
            }
        }

        self::fail('Le tirage a désigné quelqu\'un qui n\'est pas membre.');
    }

    /**
     * Le suffixe du `type` RFC 9457 — c'est dessus que le client branche ses messages, et il
     * ne change jamais, contrairement au `detail`.
     *
     * @param array<mixed> $problem
     */
    private static function typeOf(array $problem): string
    {
        self::assertIsString($type = $problem['type'] ?? null);

        return basename($type);
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
