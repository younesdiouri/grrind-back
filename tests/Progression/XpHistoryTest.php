<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Progression\UI\Http\Request\XpHistoryQuery;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProgrammableModifiers;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/progression/history` : l'écran « d'où vient mon XP ».
 *
 * Ce que cet écran doit dire ne tient pas dans un montant. Chaque écriture porte son détail
 * ligne à ligne — le socle, ce que les garde-fous ont rogné, ce que la série a ajouté — et
 * la version des règles sous lesquelles ce détail a été calculé.
 */
final class XpHistoryTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;
    private XpTransactionRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;

        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;
    }

    public function testAnAccountWithoutAnyCreditSeesAnEmptyPage(): void
    {
        $page = self::decode($this->get('/api/progression/history', $this->openAccount()->headers));

        // Vide et non 404 : ne rien avoir gagné est un état normal, pas une erreur.
        self::assertSame([], $page['transactions']);
        self::assertNull($page['nextCursor']);
    }

    public function testEachWritingCarriesItsBreakdownAndTheRulesUnderWhichItWasComputed(): void
    {
        $account = $this->openAccount();

        // Un bonus réel, pour que le détail ait plus d'une ligne à expliquer.
        ProgrammableModifiers::grant(new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Streak));
        $granted = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $page = self::decode($this->get('/api/progression/history', $account->headers));
        self::assertIsArray($page['transactions']);
        self::assertCount(1, $page['transactions']);

        $transaction = $page['transactions'][0];
        self::assertIsArray($transaction);
        self::assertSame($granted->award->amount(), $transaction['amount']);
        self::assertSame('SESSION_COMPLETED', $transaction['reason']);
        self::assertSame('RUNNING', $transaction['discipline']);
        self::assertSame(3600, $transaction['durationSeconds']);
        self::assertSame($granted->award->rulesetVersion, $transaction['rulesetVersion']);

        // Le détail, dans l'ordre où le client l'animera : d'abord ce que la séance vaut,
        // ensuite ce que la série y ajoute.
        $base = $granted->award->breakdown->lines[0]->amount;
        self::assertSame(
            [
                ['source' => XpBreakdownSource::Base->value, 'amount' => $base],
                ['source' => XpBreakdownSource::Streak->value, 'amount' => intdiv($base * 20, 100)],
            ],
            $transaction['breakdown'],
        );

        // Le même montant, réparti par caractéristique (#159, #163) plutôt que par source :
        // c'est ce qui répond à « pourquoi ma Mobility stagne » sans recouper le breakdown à
        // la main. Pas de `vitality` : voir le docblock de `XpTransactionResource`.
        $attributeGains = $granted->award->attributeGains;
        self::assertSame(
            [
                'strength' => $attributeGains->strength,
                'endurance' => $attributeGains->endurance,
                'mobility' => $attributeGains->mobility,
                'dexterity' => $attributeGains->dexterity,
            ],
            $transaction['attributes'],
        );
        self::assertArrayNotHasKey('vitality', $transaction['attributes']);
    }

    public function testAnInvalidationCarriesTheOppositeAttributeSplitRatherThanHidingIt(): void
    {
        $account = $this->openAccount();
        $sessionId = Uuid::v7();
        $granted = ($this->grantXp)(new GrantXp($account->id, $sessionId, Discipline::Running, 3600, new DateTimeImmutable()));

        // Aucun handler d'invalidation n'existe encore côté application (#91 pas fait) : le
        // ledger est écrit directement, comme le fait déjà `LedgerTest` pour la même raison.
        $credit = $this->ledger->recordedFor($sessionId, XpReason::SessionCompleted);
        self::assertInstanceOf(XpTransaction::class, $credit);
        $this->ledger->add(XpTransaction::reversalOf($credit));
        $this->ledger->commit();

        $page = self::decode($this->get('/api/progression/history', $account->headers));
        self::assertIsArray($page['transactions']);
        self::assertCount(2, $page['transactions']);

        // La plus récente d'abord : l'annulation, dont la répartition solde exactement
        // celle du crédit — des valeurs négatives, lisibles et non masquées.
        $reversal = $page['transactions'][0];
        self::assertIsArray($reversal);
        self::assertSame('SESSION_INVALIDATED', $reversal['reason']);

        $attributeGains = $granted->award->attributeGains;
        self::assertSame(
            [
                'strength' => -$attributeGains->strength,
                'endurance' => -$attributeGains->endurance,
                'mobility' => -$attributeGains->mobility,
                'dexterity' => -$attributeGains->dexterity,
            ],
            $reversal['attributes'],
        );
    }

    public function testTheMostRecentComesFirst(): void
    {
        $account = $this->openAccount();
        $sessions = $this->creditThree($account);

        $page = self::decode($this->get('/api/progression/history', $account->headers));

        self::assertSame(array_reverse($sessions), self::sourceIdsOf($page));
    }

    public function testAPageStopsAtTheLimitAndTheCursorOpensTheNext(): void
    {
        $account = $this->openAccount();
        $sessions = array_reverse($this->creditThree($account));

        $first = self::decode($this->get('/api/progression/history?limit=2', $account->headers));
        self::assertSame(\array_slice($sessions, 0, 2), self::sourceIdsOf($first));

        // Le curseur désigne une position dans les données et non un rang : la page ne
        // glisse pas si une séance se crédite pendant le défilement.
        self::assertIsString($first['nextCursor']);
        $second = self::decode($this->get('/api/progression/history?limit=2&cursor='.$first['nextCursor'], $account->headers));

        self::assertSame(\array_slice($sessions, 2), self::sourceIdsOf($second));
        self::assertNull($second['nextCursor'], 'Plus rien après : le client s\'arrête là, sans total.');
    }

    public function testTheLastPageOfAnExactMultipleDoesNotAnnounceANextOne(): void
    {
        $account = $this->openAccount();
        $this->creditThree($account);

        // Trois écritures, trois par page : une ligne de plus est lue à chaque appel, elle
        // n'existe pas, donc il n'y a pas de suite à annoncer.
        self::assertNull(self::decode($this->get('/api/progression/history?limit=3', $account->headers))['nextCursor']);
    }

    public function testTheDetailOfAWholePageCostsOneQuery(): void
    {
        $account = $this->openAccount();
        $this->creditThree($account);

        $this->get('/api/progression/history', $account->headers);

        // Le détail est chargé pour toute la page d'un coup. Sans ça, chaque `breakdown()`
        // déclencherait le sien au moment de sérialiser — vingt écritures, vingt
        // allers-retours, et un écran dont le coût croît avec la taille de la page. Ce
        // serait invisible en test comme en production : la réponse resterait juste.
        self::assertSame(1, self::queriesTouching('xp_transaction_line'));
    }

    public function testRefusesAPageBiggerThanTheCeiling(): void
    {
        $account = $this->openAccount();

        // Sinon un client demanderait tout l'historique en une requête.
        $response = $this->get('/api/progression/history?limit='.(XpHistoryQuery::MAX_LIMIT + 1), $account->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRefusesACursorThatIsNotAnIdentifier(): void
    {
        $account = $this->openAccount();

        // Le typage suffit : le refus du Serializer devient un 422 nommant le paramètre,
        // et rien de tout ça n'atteint la base.
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->get('/api/progression/history?cursor=pas-un-uuid', $account->headers)->getStatusCode(),
        );
    }

    public function testDoesNotShowTheLedgerOfAnotherAccount(): void
    {
        $mine = $this->openAccount();
        $theirs = $this->openAccount('alice@grrind.app', 'Alice');

        ($this->grantXp)(new GrantXp($theirs->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        // Le propriétaire est une condition de la recherche, pas un contrôle qui suit.
        self::assertSame([], self::decode($this->get('/api/progression/history', $mine->headers))['transactions']);
    }

    public function testRequiresAToken(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/progression/history')->getStatusCode());
    }

    /**
     * Trois crédits, dans l'ordre où ils ont été écrits.
     *
     * @return list<string> les identifiants de séance, du plus ancien au plus récent
     */
    private function creditThree(Account $account): array
    {
        $sessions = [];

        foreach ([Discipline::Running, Discipline::Cycling, Discipline::Swimming] as $discipline) {
            $sessionId = Uuid::v7();
            ($this->grantXp)(new GrantXp($account->id, $sessionId, $discipline, 1800, new DateTimeImmutable()));
            $sessions[] = $sessionId->toRfc4122();
        }

        return $sessions;
    }

    /**
     * Les requêtes SQL de la dernière requête HTTP qui mentionnent cette table.
     *
     * Le compteur vient de la pile de débogage de Doctrine, celle-là même qui alimente le
     * profileur. Elle est remise à zéro entre deux requêtes par le `services_resetter`,
     * donc ce qu'on lit ici est bien le coût du seul appel qui vient d'avoir lieu.
     */
    private static function queriesTouching(string $table): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        $matching = 0;

        foreach ($holder->getData() as $queries) {
            self::assertIsArray($queries);

            foreach ($queries as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['sql']);

                if (str_contains($query['sql'], $table)) {
                    ++$matching;
                }
            }
        }

        return $matching;
    }

    /**
     * @param array<mixed> $page
     *
     * @return list<string>
     */
    private static function sourceIdsOf(array $page): array
    {
        self::assertIsArray($page['transactions']);

        $sourceIds = [];

        foreach ($page['transactions'] as $transaction) {
            self::assertIsArray($transaction);
            self::assertIsString($transaction['sourceId']);
            $sourceIds[] = $transaction['sourceId'];
        }

        return $sourceIds;
    }
}
