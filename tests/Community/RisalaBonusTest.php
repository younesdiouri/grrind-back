<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\RevealRisalat;
use App\Community\Application\RevealRisalatHandler;
use App\Community\Application\RisalaModifiers;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'une Risāla vaut réellement à un joueur, et jusqu'où ça remonte.
 *
 * La mise en place est la vraie : la Risāla est **révélée par la bascule**, pas posée en base
 * à la main. C'est ce qui fait qu'un test qui passe ici dit quelque chose du produit — un
 * `INSERT` de fixture pourrait décrire un état que `Risala::seal()` ne sait pas produire.
 *
 * **La semaine de jeu est calée deux semaines avant maintenant**, et pas sur une date fixe :
 * la Risāla doit être révélée *dans le passé* et vivante *maintenant*, pour qu'un workout
 * réel — donc daté d'hier et dans la fenêtre d'import de trente jours — tombe dedans.
 */
final class RisalaBonusTest extends ApiTestCase
{
    private MockClock $clock;
    private RevealRisalatHandler $reveal;
    private RisalaRepository $risalat;
    private GuildRepository $guilds;
    private CreditingDisciplines $crediting;
    private RisalaModifiers $modifiers;
    private Guild $guild;

    public function testAMemberIsBonusedOnTheChallengedDisciplineAndOnItAlone(): void
    {
        [$sender, $recipient] = $this->guildWithALiveRisala(Discipline::Climbing);

        self::assertEquals(
            [new Modifier(ModifierType::XpMultiplier, 150, ModifierSource::Guild, Discipline::Climbing)],
            $this->modifiers->modifiersOf($recipient->id, $this->now()),
        );

        // La portée est la discipline : des bottes de course ne servent à rien en natation, une
        // Risāla escalade non plus. C'est `Modifier::appliesTo()` qui tranchera chez le
        // consommateur, et c'est pour ça que la portée voyage avec le modificateur.
        self::assertNotEquals($sender->id, $recipient->id);
    }

    public function testTheSenderGetsHalfAsMuchOnHisOwnRisala(): void
    {
        [$sender] = $this->guildWithALiveRisala(Discipline::Climbing);

        // Assez pour que proposer ne soit pas un sacrifice, pas assez pour qu'on propose le
        // sport qu'on pratique déjà — sans quoi la mécanique se retournerait en une semaine.
        self::assertEquals(
            [new Modifier(ModifierType::XpMultiplier, 50, ModifierSource::Guild, Discipline::Climbing)],
            $this->modifiers->modifiersOf($sender->id, $this->now()),
        );
    }

    public function testASessionOlderThanTheRevealIsNotBonused(): void
    {
        [, $recipient] = $this->guildWithALiveRisala(Discipline::Climbing);

        $revealedAt = $this->liveRisala()->revealedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $revealedAt);

        // Le #190 en une assertion : la Risāla n'existait pas encore ce jour-là, donc elle ne
        // bonifie pas cette séance — même si c'est aujourd'hui qu'on la synchronise.
        self::assertSame([], $this->modifiers->modifiersOf($recipient->id, $revealedAt->modify('-1 day')));
    }

    public function testAPlayerWithoutAGuildGetsNothing(): void
    {
        $this->guildWithALiveRisala(Discipline::Climbing);

        $stranger = $this->openAccount('solo@grrind.app', 'Solo');

        // Un tableau vide, jamais une exception : le contrat du port est explicite là-dessus,
        // et une panne ici ferait échouer l'import de quelqu'un qui n'a rien demandé.
        self::assertSame([], $this->modifiers->modifiersOf($stranger->id, $this->now()));
    }

    /**
     * Les deux rôles cohabitent chez la même personne, et c'est le régime établi : la rotation
     * fait qu'on est destinataire d'une Risāla pendant qu'on est expéditeur de l'autre.
     */
    public function testTwoLiveRisalatDoNotStepOnEachOther(): void
    {
        [, $recipient] = $this->guildWithALiveRisala(Discipline::Climbing);

        // Dans une guilde de deux, le tour suivant revient forcément à l'autre membre — c'est
        // la rotation, pas le hasard : tant que tout le monde n'a pas envoyé la sienne, on ne
        // peut pas être tiré deux fois.
        $this->choose(Discipline::Swimming);
        $this->advanceToTheReveal();

        // Deux disciplines, deux portées, deux taux — et aucun des deux ne déborde sur l'autre.
        // Un modificateur global, ou une addition des deux, serait la panne silencieuse que la
        // portée existe pour empêcher.
        self::assertEquals(
            [
                new Modifier(ModifierType::XpMultiplier, 150, ModifierSource::Guild, Discipline::Climbing),
                new Modifier(ModifierType::XpMultiplier, 50, ModifierSource::Guild, Discipline::Swimming),
            ],
            $this->modifiers->modifiersOf($recipient->id, $this->now()),
        );
    }

    /**
     * **Le test qui porte le ticket** : le bonus ne vaut que s'il arrive jusqu'au joueur, avec
     * la ligne qui l'explique. Un montant juste et une animation muette serait un demi-échec.
     */
    public function testTheBonusReachesTheImportedRewardSummary(): void
    {
        [, $recipient] = $this->guildWithALiveRisala(Discipline::Climbing);

        $startedAt = new DateTimeImmutable('-1 day')->setTime(18, 0);

        $body = self::decode($this->post('/api/workouts/import', ['workouts' => [[
            'externalId' => 'HK-ESCALADE',
            'source' => 'APPLE_HEALTH',
            'activityType' => 'climbing',
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $startedAt->modify('+1 hour')->format(DateTimeInterface::ATOM),
        ]]], $recipient->headers + ['Idempotency-Key' => 'la-premiere-fois-en-salle']));

        self::assertIsArray($imported = $body['imported']);
        self::assertCount(1, $imported);
        self::assertIsArray($first = $imported[0]);
        self::assertIsArray($xp = $first['xp']);

        // Une heure d'escalade : 60 de socle, et 90 grâce à la Risāla. Le plafond quotidien de
        // la discipline est à 200, donc rien n'est écrêté et le total se lit tel quel.
        self::assertSame(150, $xp['awarded']);
        self::assertSame(
            [['source' => 'BASE', 'amount' => 60], ['source' => 'GUILD', 'amount' => 90]],
            $xp['breakdown'],
        );
    }

    /**
     * Monte une guilde de deux, fait tirer un tour, le fait choisir et révéler. Rend
     * l'expéditeur du tour révélé et l'autre membre — dans cet ordre, quel que soit le tirage.
     *
     * @return array{Account, Account}
     */
    private function guildWithALiveRisala(Discipline $discipline): array
    {
        $founder = $this->openAccount();
        $response = $this->post('/api/guilds', ['name' => 'Les Increvables'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);

        $code = self::decode($this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers))['code'];
        self::assertIsString($code);

        $member = $this->openAccount('baha@grrind.app', 'Baha');
        self::assertSame(Response::HTTP_OK, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());

        // Après le dernier appel HTTP : le `KernelBrowser` redémarre le noyau à chaque
        // requête, ce qui reconstruirait l'horloge à son instant de départ.
        $this->clock = self::service(MockClock::class);
        $this->reveal = self::service(RevealRisalatHandler::class);
        $this->risalat = self::service(RisalaRepository::class);
        $this->guilds = self::service(GuildRepository::class);
        $this->crediting = self::service(CreditingDisciplines::class);
        $this->modifiers = self::service(RisalaModifiers::class);

        $guild = $this->guilds->ofId(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);
        $this->guild = $guild;

        // Deux semaines avant maintenant : la révélation tombera il y a une semaine, et
        // l'expiration dans une semaine. Une date fixe rendrait ce test faux au bout d'un mois.
        $this->clock->modify(new DateTimeImmutable('-2 weeks')->format('Y-m-d H:i:sP'));

        ($this->reveal)(new RevealRisalat());
        $this->choose($discipline);
        $this->advanceToTheReveal();

        $sender = $this->liveRisala()->senderId();

        return $sender->equals($founder->id) ? [$founder, $member] : [$member, $founder];
    }

    /** Avance jusqu'à l'échéance du tour ouvert, puis la fait tomber. */
    private function advanceToTheReveal(): void
    {
        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);

        $this->clock->sleep($turn->deadline()->getTimestamp() - $this->clock->now()->getTimestamp());

        ($this->reveal)(new RevealRisalat());
    }

    private function choose(Discipline $discipline): void
    {
        $turn = $this->risalat->openTurnOf($this->guild);
        self::assertInstanceOf(Risala::class, $turn);

        $turn->choose($discipline, $this->crediting, [], $this->clock->now());
        $this->risalat->commit();
    }

    private function liveRisala(): Risala
    {
        $live = $this->risalat->liveIn($this->guild, $this->now());
        self::assertNotSame([], $live, 'La Risāla devait être vivante maintenant.');

        return $live[0];
    }

    /** L'instant réel, et non celui de l'horloge pilotée : c'est lui que l'import verra. */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
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
