<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure;

use App\Combat\Application\FighterFactory;
use App\Combat\UI\Http\Response\FighterResource;
use App\Shared\Application\PlayerProgression;
use App\Shared\Application\PlayerStatistics;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Publie le même combattant et les mêmes pourcentages que les routes de combat. */
final readonly class ResolvedPlayerStatistics implements PlayerStatistics
{
    public function __construct(private FighterFactory $fighters, private ClockInterface $clock)
    {
    }

    public function of(Uuid $playerId, PlayerProgression $progression): array
    {
        $resolved = $this->fighters->resolvePlayer($progression, $playerId, $this->clock->now());
        $fighter = $resolved['fighter'];

        return [
            'attributes' => $resolved['attributes'],
            'fighter' => FighterResource::from([
                'hp' => $fighter->hp,
                'damage' => $fighter->damage,
                'mitigationPermille' => $fighter->mitigationPermille,
                'comboPermille' => $fighter->comboPermille,
                'dodgePermille' => $fighter->dodgePermille,
                'maintenancePermille' => $fighter->maintenancePermille,
                'criticalChancePermille' => $fighter->criticalChancePermille,
                'guardPermille' => $fighter->guardPermille,
                'criticalResistancePermille' => $fighter->criticalResistancePermille,
                'cooldownReductionPermille' => $fighter->cooldownReductionPermille,
                'precisionPermille' => $fighter->precisionPermille,
            ])->toArray(),
        ];
    }
}
