<?php

declare(strict_types=1);

namespace App\Combat\UI\Console;

use App\Combat\Application\FighterFactory;
use App\Combat\Domain\Attack;
use App\Combat\Domain\BattleEndReason;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Dodge;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\Fighter;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use DateTimeImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/** Comparaison reproductible, sans équipement ni bonus d'énergie ; Vitality du snapshot.
 * Tous les duels orientés et symétriques utilisent les mêmes graines entières.
 * Les moyennes sont un rapport d'analyse, jamais une valeur de jeu persistée. */
#[AsCommand(name: 'app:combat:balance', description: 'Compare 11 profils à trois budgets, sans mutation du jeu')]
final class CombatBalanceCommand extends Command
{
    public function __construct(private GameRulesets $rulesets, private Vitality $vitality, private EnemyCatalog $enemies)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Graines par duel (1..10000)', '100');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'CSV de sortie', 'var/combat-balance-v2.csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $samples = filter_var($input->getOption('samples'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $path = $input->getOption('output');
        if (false === $samples || !\is_string($path)) {
            return Command::INVALID;
        }
        $file = fopen($path, 'w');
        if (false === $file) {
            return Command::FAILURE;
        }
        $snapshot = $this->rulesets->snapshot();
        /** @var array{combat: array{fighter: array<string, int>}} $snapshot */
        $rules = CombatRules::fromSnapshot($snapshot['combat']['fighter']);
        $factory = new FighterFactory($rules, new ModifierResolver([]));
        $simulator = new BattleSimulator($rules);
        fputcsv($file, ['ruleset', 'budget', 'player', 'enemy', 'samples', 'player_hp', 'player_damage', 'wins', 'limits', 'mean_ticks', 'mean_attacks', 'mean_chain', 'max_chain'], escape: '');
        $fights = 0;
        foreach ([4000, 40000, 400000] as $budget) {
            $profiles = $this->profiles($factory, $budget);
            foreach ($profiles as $name => $player) {
                foreach ($profiles as $opponent => $enemy) {
                    $wins = $limits = $ticks = $attacks = $actions = $longest = 0;
                    for ($seed = 0; $seed < $samples; ++$seed) {
                        $outcome = $simulator->fight($player, $enemy, new Randomizer(new Xoshiro256StarStar($seed)));
                        $wins += BattleResult::Victory === $outcome->result ? 1 : 0;
                        $limits += BattleEndReason::AttackLimit === $outcome->endReason ? 1 : 0;
                        $ticks += $outcome->elapsedTicks;
                        $attacks += $outcome->attackCount;
                        $actions += $outcome->actionCount;
                        $chain = $lastAction = 0;
                        foreach ($outcome->timeline as $event) {
                            if ($event instanceof Attack || $event instanceof Dodge) {
                                $chain = $event->actionIndex === $lastAction ? $chain + 1 : 1;
                                $lastAction = $event->actionIndex;
                                $longest = max($longest, $chain);
                            }
                        }
                        ++$fights;
                    }
                    fputcsv($file, [$this->rulesets->version(), $budget, $name, $opponent, $samples, $player->hp, $player->damage, $wins, $limits, round($ticks / $samples, 2), round($attacks / $samples, 2), round($attacks / $actions, 3), $longest], escape: '');
                }
            }
        }
        fclose($file);
        $output->writeln(\sprintf('%d combats, %d graines par duel, snapshot %s → %s', $fights, $samples, $this->rulesets->version(), $path));
        $this->topTierReport($factory, $simulator, $output);

        return Command::SUCCESS;
    }

    /** Les douze graines exactes de l'ancien test de difficulté, sans changer le catalogue. */
    private function topTierReport(FighterFactory $factory, BattleSimulator $simulator, OutputInterface $output): void
    {
        $player = $this->profiles($factory, 75460)['SEMD'];
        foreach ([$this->enemies->forLevel(50), $this->enemies->findBoss('CINDER_SOVEREIGN')] as $enemy) {
            if (null === $enemy) {
                continue;
            }
            $wins = $limits = $ticks = $attacks = 0;
            for ($i = 1; $i <= 12; ++$i) {
                $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', "veteran-{$i}", true)));
                $outcome = $simulator->fight($player, $factory->forEnemy($enemy), $rng);
                $wins += BattleResult::Victory === $outcome->result ? 1 : 0;
                $limits += BattleEndReason::AttackLimit === $outcome->endReason ? 1 : 0;
                $ticks += $outcome->elapsedTicks;
                $attacks += $outcome->attackCount;
            }
            $output->writeln(\sprintf('Scénario veteran-1..12, budget 75460, %s : %d/12 victoires, %d limites, %.2f ticks et %.2f tentatives en moyenne.', $enemy->key, $wins, $limits, $ticks / 12, $attacks / 12));
        }
    }

    /** @return array<string, Fighter> */
    private function profiles(FighterFactory $factory, int $budget): array
    {
        $profiles = [];
        foreach (['S' => [0], 'E' => [1], 'M' => [2], 'D' => [3], 'SE' => [0, 1], 'SM' => [0, 2], 'SD' => [0, 3], 'EM' => [1, 2], 'ED' => [1, 3], 'MD' => [2, 3], 'SEMD' => [0, 1, 2, 3]] as $name => $indices) {
            $values = [0, 0, 0, 0];
            foreach ($indices as $index) {
                $values[$index] = intdiv($budget, \count($indices));
            }
            $attributes = new AttributeGains(...$values);
            $progression = new PlayerProgression(1, 0, null, null, $attributes, $this->vitality->of($attributes), $this->vitality->explain(0));
            $profiles[$name] = $factory->forPlayer($progression, Uuid::fromString('00000000-0000-7000-8000-000000000001'), new DateTimeImmutable('2026-09-08T00:00:00Z'));
        }

        return $profiles;
    }
}
