<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Application\ProgressionStateProvider;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\Vitality;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * `ProgressionState` porte les quatre caractéristiques (#160) et Vitality (#163). C'est
 * l'assemblage qui se prouve ici, pas le contrat HTTP — voir `ProgressionStateTest` pour
 * `GET /api/progression`, qui les expose depuis #163.
 */
final class ProgressionStateProviderTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;
    private ProgressionStateProvider $states;
    private XpTransactionRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();

        $grantXp = $container->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;

        $states = $container->get(ProgressionStateProvider::class);
        self::assertInstanceOf(ProgressionStateProvider::class, $states);
        $this->states = $states;

        $ledger = $container->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;
    }

    public function testANewAccountHasNothingOnAnyAttribute(): void
    {
        $account = $this->openAccount();

        $state = $this->states->of($account->id);

        self::assertEquals(new AttributeGains(0, 0, 0, 0), $state->attributes);
        self::assertSame(0, $state->vitality);
    }

    public function testCarriesTheFourAttributesFromTheSnapshot(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $state = $this->states->of($account->id);

        // Assemblé du snapshot, comme le reste de l'état — voir le docblock de
        // `ProgressionStateProvider`. Le ledger reste la référence pour vérifier que le
        // snapshot n'a rien perdu au passage.
        self::assertEquals($this->ledger->attributeTotalsOf($account->id), $state->attributes);
        self::assertGreaterThan(0, $state->attributes->total());
    }

    public function testCarriesVitalityFromTheSnapshotRatherThanRederivingIt(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $state = $this->states->of($account->id);

        // Vitality (#161) se dérive des quatre caractéristiques, jamais du ledger
        // directement — la comparaison passe donc par le même calcul plutôt que par un
        // second appel au ledger, et prouve que `ProgressionState` la lit sur le snapshot
        // sans la rederiver (#163).
        self::assertSame(self::vitality()->of($state->attributes), $state->vitality);
        self::assertGreaterThan(0, $state->vitality);
    }

    /**
     * Le plancher livré, construit depuis son paramètre : le conteneur n'expose pas
     * `Vitality` en dehors du handler — même geste qu'à `GrantXpTest`.
     */
    private static function vitality(): Vitality
    {
        $floorPermille = self::getContainer()->getParameter('game.attributes.vitality.floor_permille');
        self::assertIsInt($floorPermille);

        return new Vitality($floorPermille);
    }
}
