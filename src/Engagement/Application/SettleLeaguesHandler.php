<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Domain\LeagueRules;
use App\Engagement\Domain\LeagueWeek;
use App\Engagement\Infrastructure\Doctrine\LeagueRepository;
use App\Shared\Application\GameRulesets;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Une transaction par semaine, verrou sur sa période, résultats et prochaine période au même COMMIT.
 * Le battement rattrape chaque semaine manquée. L'historique conserve la version de la clôture.
 * https://symfony.com/doc/current/scheduler.html.
 */
final readonly class SettleLeaguesHandler
{
    public function __construct(private LeagueRepository $leagues, private LeagueStateProvider $states, private GameRulesets $rulesets, private Connection $connection, private ClockInterface $clock)
    {
    }

    #[AsMessageHandler]
    public function __invoke(SettleLeagues $message): void
    {
        $now = $this->clock->now();
        $rules = LeagueRules::fromSnapshot($this->rulesets->snapshot());
        while ($this->connection->transactional(function () use ($now, $rules): bool {
            $period = $this->leagues->lockOpenPeriod();
            if (null === $period) {
                $this->leagues->open(LeagueWeek::containing($now));

                return false;
            }
            if (new DateTimeImmutable($period['ends_at']) > $now) {
                return false;
            }
            $week = LeagueWeek::containing(new DateTimeImmutable($period['starts_at']));
            $this->leagues->settle($week, $rules->evaluate($this->states->participants($week)), $now, $this->rulesets->version());

            return true;
        })) {
        }
    }
}
