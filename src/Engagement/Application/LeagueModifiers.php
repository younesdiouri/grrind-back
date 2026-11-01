<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Domain\LeagueRules;
use App\Engagement\Domain\LeagueTier;
use App\Engagement\Domain\LeagueWeek;
use App\Engagement\Infrastructure\Doctrine\LeagueRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierContributor;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/** La ligue s'applique à la semaine du sport ; une promotion d'aujourd'hui ne bonifie jamais la semaine dernière. */
final readonly class LeagueModifiers implements ModifierContributor
{
    public function __construct(private LeagueRepository $leagues, private GameRulesets $rulesets)
    {
    }

    public function modifiersOf(Uuid $userId, DateTimeImmutable $occurredAt): array
    {
        $tier = $this->leagues->tiersBefore(LeagueWeek::containing($occurredAt)->startsAt, [$userId])[$userId->toRfc4122()] ?? LeagueTier::Bronze;
        $luck = LeagueRules::fromSnapshot($this->rulesets->snapshot())->lootLuck($tier);

        return 0 === $luck ? [] : [new Modifier(ModifierType::LootLuck, $luck, ModifierSource::League)];
    }
}
