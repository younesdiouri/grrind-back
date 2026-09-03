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
use App\Shared\Domain\Activity\WorkoutSource;
use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use App\Tests\Support\ApiTestCase;
use App\Training\Application\DailyActivityEntry;
use App\Training\Application\UpsertDailyActivity;
use App\Training\Application\UpsertDailyActivityHandler;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * `ProgressionState` porte les quatre caractéristiques (#160) et Vitality (#163),
 * désormais bonifiée par l'énergie active de la fenêtre (#165). C'est l'assemblage qui se
 * prouve ici, pas le contrat HTTP — voir `ProgressionStateTest` pour `GET /api/progression`.
 */
final class ProgressionStateProviderTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;
    private ProgressionStateProvider $states;
    private XpTransactionRepository $ledger;
    private UpsertDailyActivityHandler $upsertDailyActivity;

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

        $upsertDailyActivity = $container->get(UpsertDailyActivityHandler::class);
        self::assertInstanceOf(UpsertDailyActivityHandler::class, $upsertDailyActivity);
        $this->upsertDailyActivity = $upsertDailyActivity;
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
     * Le cœur du #165 : une énergie active moyenne qui atteint la cible bonifie la Vitality
     * du plafond configuré, au-delà de ce que le snapshot porte seul.
     */
    public function testAppliesTheVitalityBonusFromActiveEnergyOnTheWindow(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $attributes = self::getContainer()->get(\App\Shared\Application\GameRulesets::class)->snapshot()['attributes'];
        self::assertIsArray($attributes);
        $vitality = $attributes['vitality'] ?? null;
        self::assertIsArray($vitality);
        $target = $vitality['target_active_kcal'] ?? null;
        $windowDays = $vitality['window_days'] ?? null;
        self::assertIsInt($target);
        self::assertIsInt($windowDays);

        // Le même jour que celui que `ProgressionStateProvider` calculera à la lecture —
        // `openAccount()` inscrit toujours en `Europe/Paris`. La cible est envoyée sur
        // **toute** la fenêtre, pas seulement aujourd'hui : la moyenne divise par
        // `windowDays`, une seule journée renseignée ne suffirait qu'à `target / windowDays`.
        $today = LocalDay::containing(new DateTimeImmutable(), Timezone::fromString('Europe/Paris'))->date;

        $entries = [];
        for ($day = 0; $day < $windowDays; ++$day) {
            $entries[] = new DailyActivityEntry(new DateTimeImmutable($today.' -'.$day.' days'), $target, WorkoutSource::AppleHealth);
        }

        ($this->upsertDailyActivity)(new UpsertDailyActivity($account->id, $entries));

        $state = $this->states->of($account->id);
        $base = self::vitality()->of($state->attributes);

        self::assertSame(self::vitality()->bonused($base, $target), $state->vitality);
        self::assertGreaterThan($base, $state->vitality, 'La journée active doit bonifier la Vitality au-delà de sa base.');
        self::assertSame($target, $state->vitalityBreakdown->windowAverageActiveKcal);
    }

    /**
     * Le plancher, la cible et le plafond livrés, construits depuis leurs paramètres : le
     * conteneur n'expose pas `Vitality` en dehors du handler — même geste qu'à `GrantXpTest`.
     */
    private static function vitality(): Vitality
    {
        $vitality = self::getContainer()->get(Vitality::class);
        self::assertInstanceOf(Vitality::class, $vitality);

        return $vitality;
    }
}
