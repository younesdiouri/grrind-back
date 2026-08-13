<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\Title;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Les titres de bout en bout : le mur, le déblocage à la complétion, la sélection, et ce
 * qu'en dit le profil.
 *
 * Le catalogue livré sert de matériau plutôt qu'une fixture : ce qui est vérifié ici, c'est
 * la mécanique, mais elle doit tenir sur la balance réellement déployée. Seul `first_steps`
 * est nommé — c'est le seul titre qu'une unique séance débloque, donc le seul dont dépendre
 * ne fige pas l'équilibrage.
 */
final class TitlesTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    public function testANewAccountSeesTheWholeCatalogueLockedAndSomethingToAimAt(): void
    {
        $account = $this->openAccount();

        $board = self::decode($this->get('/api/titles', $account->headers));

        self::assertNull($board['activeTitleId']);
        self::assertIsArray($board['titles']);
        self::assertNotEmpty($board['titles'], 'Le mur montre tout le catalogue, pas seulement l\'acquis.');

        foreach ($board['titles'] as $title) {
            self::assertIsArray($title);
            self::assertFalse($title['unlocked']);
            self::assertNull($title['unlockedAt']);
        }

        // Tout est à zéro, donc l'ordre du catalogue tranche — et il est écrit pour que le
        // premier objectif proposé soit celui qu'une séance suffit à atteindre.
        $me = self::decode($this->get('/api/me', $account->headers));
        self::assertNull($me['title']);
        self::assertIsArray($me['nextTitle']);
        self::assertSame('first_steps', $me['nextTitle']['id']);
        self::assertSame(['current' => 0, 'target' => 1, 'unit' => 'SESSIONS'], $me['nextTitle']['progress']);
    }

    public function testCompletingASessionUnlocksTheTitleThatSessionSatisfies(): void
    {
        $account = $this->openAccount();

        $granted = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));

        // La séance qui vient d'être créditée compte dans sa propre condition : évaluer
        // avant l'écriture ferait attendre la séance suivante au joueur.
        self::assertContains('first_steps', self::idsOf($granted->titlesUnlocked));

        $unlocked = self::titleIn($this->get('/api/titles', $account->headers), 'first_steps');
        self::assertTrue($unlocked['unlocked']);
        self::assertIsString($unlocked['unlockedAt']);
    }

    public function testATitleIsOnlyEverAnnouncedOnce(): void
    {
        $account = $this->openAccount();

        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));
        $second = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Cycling, 1800, new DateTimeImmutable()));

        // Sans quoi la complétion rappellerait au joueur, à chaque séance, qu'il vient de
        // débloquer un titre qu'il porte depuis six mois.
        self::assertNotContains('first_steps', self::idsOf($second->titlesUnlocked));
    }

    public function testSelectingAnUnlockedTitlePutsItOnTheProfile(): void
    {
        $account = $this->unlockedAccount();

        $board = self::decode($this->select($account, 'first_steps'));
        self::assertSame('first_steps', $board['activeTitleId']);

        // Le profil sert le titre porté et le suivant, dans la même forme : un seul type à
        // décoder côté client.
        $me = self::decode($this->get('/api/me', $account->headers));
        self::assertIsArray($me['title']);
        self::assertSame('first_steps', $me['title']['id']);
        self::assertTrue($me['title']['unlocked']);
        self::assertIsString($me['title']['unlockedAt']);

        // Un titre acquis ne revient jamais dans les objectifs.
        self::assertIsArray($me['nextTitle']);
        self::assertNotSame('first_steps', $me['nextTitle']['id']);
    }

    public function testSelectingIsIdempotentAndReversible(): void
    {
        $account = $this->unlockedAccount();

        $this->select($account, 'first_steps');
        self::assertSame('first_steps', self::decode($this->select($account, 'first_steps'))['activeTitleId']);

        // « Je ne porte plus rien » est un geste que le joueur peut vouloir, et il ne
        // mérite pas une seconde route.
        self::assertNull(self::decode($this->select($account, null))['activeTitleId']);
        self::assertNull(self::decode($this->get('/api/me', $account->headers))['title']);
    }

    public function testATitleThatIsNotUnlockedCannotBeWorn(): void
    {
        $account = $this->openAccount();

        $response = $this->select($account, 'first_steps', Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame('https://grrind.app/problems/title-not-unlocked', self::decode($response)['type']);
    }

    public function testAnUnknownTitleIsANotFound(): void
    {
        $account = $this->openAccount();

        // Le cas courant n'est pas la faute de frappe mais le client resté ouvert sur un
        // catalogue que le déploiement d'hier a modifié.
        $response = $this->select($account, 'chevalier_du_zodiaque', Response::HTTP_NOT_FOUND);

        self::assertSame('https://grrind.app/problems/title-unknown', self::decode($response)['type']);
    }

    public function testTheWallIsPrivate(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/titles')->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->send('PUT', '/api/titles/active', ['titleId' => null])->getStatusCode());
    }

    public function testTitlesAreServedInTheLanguageTheClientAsksFor(): void
    {
        $account = $this->openAccount();

        self::assertSame('Premiers pas', $this->firstStepsNameFor($account, 'fr'));
        self::assertSame('First Steps', $this->firstStepsNameFor($account, 'en-US,en;q=0.9'));

        // Une langue qu'on ne parle pas encore retombe sur l'anglais. Sans repli, la clé
        // brute partirait sur le réseau et le joueur lirait `first_steps.name`.
        self::assertSame('First Steps', $this->firstStepsNameFor($account, 'de'));
    }

    private function firstStepsNameFor(Account $account, string $acceptLanguage): string
    {
        $title = self::titleIn(
            $this->get('/api/titles', $account->headers + ['Accept-Language' => $acceptLanguage]),
            'first_steps',
        );

        self::assertIsString($title['name']);

        return $title['name'];
    }

    /** Un compte qui a fait une séance, donc qui possède `first_steps`. */
    private function unlockedAccount(): Account
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));

        return $account;
    }

    private function select(Account $account, ?string $titleId, int $expected = Response::HTTP_OK): Response
    {
        $response = $this->send('PUT', '/api/titles/active', ['titleId' => $titleId], $account->headers);

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function titleIn(Response $response, string $titleId): array
    {
        $board = self::decode($response);
        self::assertIsArray($board['titles']);

        foreach ($board['titles'] as $title) {
            if (\is_array($title) && $titleId === ($title['id'] ?? null)) {
                /** @var array<string, mixed> $title */
                return $title;
            }
        }

        self::fail(\sprintf('Aucun titre "%s" au mur.', $titleId));
    }

    /**
     * @param list<Title> $titles
     *
     * @return list<string>
     */
    private static function idsOf(array $titles): array
    {
        return array_map(static fn (Title $title): string => $title->id, $titles);
    }
}
