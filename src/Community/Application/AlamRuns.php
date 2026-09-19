<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AlamMode;
use App\Community\Domain\AlamNarrationSource;
use App\Community\Domain\AlamRun;
use App\Community\Domain\AlamStatus;
use App\Community\Domain\Exception\AlamManualDisabled;
use App\Community\Domain\Exception\AlamRunNotFound;
use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildMembership;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Shared\Application\AlamContributions;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Shared\Domain\Alam\AlamRules;
use App\Shared\UI\Http\Cursor;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le verrou de guilde sérialise révélation, résolution et changements de roster. Une première
 * lecture amorce seulement la semaine courante ; ensuite le scheduler rattrape les éditions
 * révélées en retard. Le COMMIT contient résultat, inventaires et demande de narration.
 */
final readonly class AlamRuns
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private GuildRepository $guilds,
        private EntityManagerInterface $em,
        private GameRulesets $rulesets,
        private AlamContributions $contributions,
        private PlayerProfiles $profiles,
        private AlamResolution $resolution,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire('%env(bool:ALAM_MANUAL_ENABLED)%')]
        public bool $manualEnabled,
    ) {
    }

    /** @return array<string, mixed> */
    public function current(Uuid $player): array
    {
        $guild = $this->guildOf($player);
        $run = $this->ensureWeek($guild->id());

        return ['serverNow' => $this->clock->now()->format('c'), 'canLaunchManual' => $this->manualEnabled, 'pollAfterSeconds' => 5, 'current' => $this->resource($run)];
    }

    public function manual(Uuid $player, string $requestKey): AlamRun
    {
        // Le même découpage que IdempotencyListener::keyOf() : la clé durable et la clé
        // HTTP ne peuvent pas différer d'un espace, sinon un rejeu crée une seconde édition.
        $durableKey = hash('sha256', $player->toRfc4122().':'.trim($requestKey));
        $existing = $this->em->getRepository(AlamRun::class)->findOneBy(['requestKey' => $durableKey]);
        if (null !== $existing) {
            return $existing;
        }
        $guild = $this->guildOf($player);
        if (!$this->manualEnabled) {
            throw new AlamManualDisabled();
        }

        return $this->manualForGuild($guild->id(), $player, $durableKey);
    }

    public function manualForGuild(Uuid $guildId, ?Uuid $actor = null, ?string $requestKey = null): AlamRun
    {
        if (!$this->manualEnabled) {
            throw new AlamManualDisabled();
        }

        return $this->em->wrapInTransaction(function () use ($guildId, $actor, $requestKey): AlamRun {
            $guild = $this->guilds->lockForUpdate($guildId) ?? throw new GuildNotFound();
            if (null !== $actor && !$guild->hasMember($actor)) {
                throw new GuildNotFound();
            }
            if (null !== $requestKey) {
                $existing = $this->em->getRepository(AlamRun::class)->findOneBy(['requestKey' => $requestKey]);
                if (null !== $existing) {
                    return $existing;
                }
            }
            $weekly = $this->ensureWeekLocked($guild);
            $now = $this->clock->now();
            $run = new AlamRun($guildId, AlamMode::Manual, $weekly->weekStartsAt, min($now, $weekly->collectionEndsAt), $now, $weekly->frozenTargetCount, $weekly->rulesetVersion, $weekly->rules);
            $run->requestKey = $requestKey;
            $this->em->persist($run);
            $this->resolveLocked($run, $guild, $now);

            return $run;
        });
    }

    public function show(Uuid $id, Uuid $player): AlamRun
    {
        $run = $this->em->find(AlamRun::class, $id) ?? throw new AlamRunNotFound();
        $membership = $this->memberships->ofPlayer($player);
        if (null !== $membership && $membership->guild()->id()->equals($run->guildId)) {
            return $run;
        }
        /** @var list<array{playerId: string}> $participants */
        $participants = $run->result['participants'] ?? [];
        foreach ($participants as $participant) {
            if ($participant['playerId'] === $player->toRfc4122()) {
                return $run;
            }
        }
        throw new AlamRunNotFound();
    }

    /** @return array{runs: list<array<string, mixed>>, nextCursor: ?string} */
    public function history(Uuid $player, int $limit, ?Cursor $cursor): array
    {
        $guild = $this->guildOf($player);
        // Révélation puis identifiant, comme les deux autres historiques : le curseur désigne
        // un couple, et l'ordonner sur le seul UUID v7 tiendrait par coïncidence — les
        // éditions naissent à leur révélation aujourd'hui, une reprise pourrait les antidater.
        $query = $this->em->createQueryBuilder()->select('r')->from(AlamRun::class, 'r')->where('r.guildId = :guild')->setParameter('guild', $guild->id(), UuidType::NAME)->orderBy('r.revealedAt', 'DESC')->addOrderBy('r.id', 'DESC')->setMaxResults($limit + 1);
        if (null !== $cursor) {
            $query
                ->andWhere('r.revealedAt < :cursorAt OR (r.revealedAt = :cursorAt AND r.id < :cursorId)')
                ->setParameter('cursorAt', $cursor->at)
                ->setParameter('cursorId', $cursor->id, UuidType::NAME);
        }
        /** @var list<AlamRun> $runs */
        $runs = $query->getQuery()->getResult();
        $hasMore = \count($runs) > $limit;
        $runs = \array_slice($runs, 0, $limit);
        $last = [] === $runs ? null : $runs[array_key_last($runs)];

        return ['runs' => array_map($this->resource(...), $runs), 'nextCursor' => $hasMore && null !== $last ? Cursor::of($last->revealedAt, $last->id)->encoded() : null];
    }

    /** @return array<string, mixed> */
    public function resource(AlamRun $run): array
    {
        $result = $run->result;
        if (null === $run->resolvedAt) {
            $guild = $this->guilds->ofId($run->guildId);
            [$participants, $totals] = null === $guild ? [[], []] : $this->roster($run, $guild);
            $result = ['participants' => $participants, 'gauges' => AlamResolution::gauges($totals, $this->rulesOf($run)->targets($run->frozenTargetCount)), 'encounters' => [], 'events' => [], 'narrationSource' => AlamNarrationSource::Local->value, 'presentationStartsAt' => null, 'presentationEndsAt' => null];
        }

        return ['id' => $run->id->toRfc4122(), 'guildId' => $run->guildId->toRfc4122(), 'mode' => $run->mode->value, 'status' => null === $run->resolvedAt ? AlamStatus::Collecting->value : AlamStatus::Resolved->value, 'weekStartsAt' => $run->weekStartsAt->format('c'), 'collectionEndsAt' => $run->collectionEndsAt->format('c'), 'revealedAt' => $run->revealedAt->format('c'), 'frozenTargetCount' => $run->frozenTargetCount, 'rulesetVersion' => $run->rulesetVersion, ...$result, 'serverNow' => $this->clock->now()->format('c')];
    }

    public function tick(): void
    {
        foreach ($this->guilds->findAll() as $guild) {
            $this->ensureWeek($guild->id());
        }
        /** @var list<AlamRun> $pending */
        $pending = $this->em->getRepository(AlamRun::class)->findBy(['resolvedAt' => null]);
        foreach ($pending as $run) {
            if ($run->collectionEndsAt <= $this->clock->now()) {
                $this->em->wrapInTransaction(function () use ($run): void {
                    // Une guilde dissoute pendant sa semaine laisse son édition en l'état :
                    // la résoudre sur un roster vide figerait un raid sans personne dedans.
                    $guild = $this->guilds->lockForUpdate($run->guildId);
                    if (null === $guild) {
                        return;
                    }
                    $this->em->refresh($run, LockMode::PESSIMISTIC_WRITE);
                    if (null === $run->resolvedAt) {
                        $this->resolveLocked($run, $guild, $this->clock->now());
                    }
                });
            }
        }
    }

    private function ensureWeek(Uuid $guildId): AlamRun
    {
        return $this->em->wrapInTransaction(fn (): AlamRun => $this->ensureWeekLocked($this->guilds->lockForUpdate($guildId) ?? throw new GuildNotFound()));
    }

    private function ensureWeekLocked(Guild $guild): AlamRun
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $this->rulesets->snapshot()['alam'];
        $calendar = new AlamCalendar(new AlamRules($configuration));
        $week = $calendar->week($this->clock->now());
        $existing = $this->em->getRepository(AlamRun::class)->findOneBy(['guildId' => $guild->id(), 'mode' => AlamMode::Weekly, 'weekStartsAt' => $week['start']]);
        if (null !== $existing) {
            return $existing;
        }
        $players = array_map(static fn (GuildMembership $member): Uuid => $member->playerId(), $guild->members());
        $count = $this->contributions->activeCount($players, $week['start']->modify('-7 days'), $week['start']);
        $run = new AlamRun($guild->id(), AlamMode::Weekly, $week['start'], $week['close'], $this->clock->now(), $count, $this->rulesets->version(), $this->rulesets->snapshot());
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function resolveLocked(AlamRun $run, Guild $guild, DateTimeImmutable $now): void
    {
        if (null !== $run->resolvedAt) {
            return;
        }
        $players = array_map(static fn (GuildMembership $member): Uuid => $member->playerId(), $guild->members());
        $this->contributions->lock($players, new FrozenGameRulesets($run->rules, $run->rulesetVersion));
        [$participants, $totals] = $this->roster($run, $guild);
        $run->result = $this->resolution->resolve($run, $participants, $totals, $now);
        $run->resolvedAt = $now;
        $this->em->flush();
        $this->bus->dispatch(new NarrateAlam($run->id->toRfc4122()));
    }

    /** @return array{list<array{playerId: string, displayName: string, avatarUrl: ?string, contribution: int, gauges: list<array{attribute: string, current: int, target: int, progressPermille: int}>}>, array<string, int>} */
    private function roster(AlamRun $run, Guild $guild): array
    {
        $players = array_map(static fn (GuildMembership $member): Uuid => $member->playerId(), $guild->members());
        $gains = $this->contributions->between($players, $run->weekStartsAt, min($this->clock->now(), $run->collectionEndsAt), new FrozenGameRulesets($run->rules, $run->rulesetVersion));
        $profiles = $this->profiles->of($players);
        $participants = [];
        $totals = [];
        foreach ($players as $player) {
            $id = $player->toRfc4122();
            $contribution = $gains[$id];
            foreach ($contribution['attributes'] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
            $participants[] = ['playerId' => $id, 'displayName' => $profiles[$id]->displayName ?? 'Aventurier', 'avatarUrl' => null, 'contribution' => $contribution['total'], 'activitySummary' => $contribution['sports'], 'gauges' => AlamResolution::gauges($contribution['attributes'], $this->rulesOf($run)->targets(1))];
        }

        return [$participants, $totals];
    }

    private function rulesOf(AlamRun $run): AlamRules
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $run->rules['alam'];

        return new AlamRules($configuration);
    }

    private function guildOf(Uuid $player): Guild
    {
        return $this->memberships->ofPlayer($player)?->guild() ?? throw new GuildNotFound();
    }
}
