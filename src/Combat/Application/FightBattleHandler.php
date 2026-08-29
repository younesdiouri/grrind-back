<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Application\PlayerProgressions;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Un combat PvE, de bout en bout : lire le joueur, choisir l'ennemi, jouer, écrire la ligne.
 *
 * ## Le pipeline, dans l'ordre
 *
 * 1. {@see PlayerProgressions} rend la progression du joueur — batch par construction, un
 *    seul élément ici, voir son docblock pour pourquoi ce port et pas un huitième ;
 * 2. {@see FighterFactory} dérive le combattant du joueur, {@see EnemyCatalog::forLevel()}
 *    choisit l'ennemi à son niveau et {@see FighterFactory::forEnemy()} le traduit à son
 *    tour — même porte que le joueur, voir le docblock de la factory ;
 * 3. `random_bytes(32)` tire la graine — jamais un hash d'une chaîne, voir le docblock de
 *    {@see Battle} pour le piège que ça a coûté au #209 ;
 * 4. {@see BattleSimulator::fight()} joue le combat, pur, sur les deux combattants et le
 *    `Randomizer` grainé ;
 * 5. {@see Battle::conclude()} écrit la ligne, jamais mutée après.
 *
 * ## Aucune récompense
 *
 * Pas d'XP, pas de loot, pas d'événement dans l'outbox — voir le ticket #211. L'XP est un
 * ledger alimenté par le sport ; en créditer pour un combat que le joueur regarde casserait
 * la prémisse du produit. La récompense viendra par le `LootRoller` audité du Lot 6, pas
 * par une addition ici.
 */
final readonly class FightBattleHandler
{
    public function __construct(
        private PlayerProgressions $progressions,
        private FighterFactory $fighters,
        private EnemyCatalog $enemies,
        private BattleSimulator $simulator,
        private BattleRepository $battles,
        private string $rulesetVersion,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(FightBattle $command): Battle
    {
        $progressions = $this->progressions->of([$command->playerId]);
        $progression = $progressions[$command->playerId->toRfc4122()];

        $player = $this->fighters->forPlayer($progression);

        $enemy = $this->enemies->forLevel($progression->level);
        $enemyFighter = $this->fighters->forEnemy($enemy);

        // Exactement 32 octets, jamais un hash d'une chaîne — voir le docblock de `Battle`.
        $seed = random_bytes(32);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

        $outcome = $this->simulator->fight($player, $enemyFighter, $randomizer);

        $battle = Battle::conclude(
            $command->playerId,
            $progression->attributes,
            $progression->vitality,
            $player,
            $enemy,
            $enemyFighter,
            $outcome,
            $seed,
            $this->rulesetVersion,
            $this->clock->now(),
        );

        $this->battles->add($battle);
        $this->battles->commit();

        return $battle;
    }
}
