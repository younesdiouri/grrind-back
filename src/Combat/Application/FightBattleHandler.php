<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\Exception\EnemyKeyUnknown;
use App\Combat\Domain\Exception\EnemyLevelTooLow;
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
 * 2. {@see FighterFactory} dérive le combattant du joueur ; l'ennemi vient soit de
 *    {@see EnemyCatalog::forLevel()} — le serveur choisit, comportement inchangé depuis le
 *    #212 — soit de {@see chosen()} quand `$command->enemyKey` est renseigné (#219) ; dans
 *    les deux cas {@see FighterFactory::forEnemy()} le traduit ensuite, même porte que le
 *    joueur, voir le docblock de la factory ;
 * 3. `random_bytes(32)` tire la graine — jamais un hash d'une chaîne, voir le docblock de
 *    {@see Battle} pour le piège que ça a coûté au #209 ;
 * 4. {@see BattleSimulator::fight()} joue le combat, pur, sur les deux combattants et le
 *    `Randomizer` grainé ;
 * 5. {@see Battle::conclude()} écrit la ligne, jamais mutée après.
 *
 * ## Le choix de l'adversaire (#219)
 *
 * **Le corps `{"enemy": "..."}` de `POST /api/battles` accepte n'importe quelle clé du
 * catalogue, boss ET ennemi ordinaire.** Le ticket #219 dit « la liste des adversaires
 * affrontables, boss compris » — `GET /api/enemies` ne distingue pas les deux, donc rien
 * n'en distingue le choix. Pour un ennemi ordinaire, son `level` — le palier auquel
 * `forLevel()` l'aurait choisi tout seul — fait alors office de niveau minimum, exactement
 * comme le `minimum_level` d'un boss.
 *
 * **Effet de bord assumé : un joueur peut réaffronter un ennemi d'un palier inférieur au
 * sien.** Sans conséquence tant qu'un combat ne rapporte rien — aucune récompense n'existe
 * encore, voir plus bas — et la question du farm se repose entièrement le jour où un
 * combat rapportera quelque chose ; elle appartient à ce ticket-là, pas au #219.
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

        // Aucune ligne n'est encore écrite à ce stade : un refus ici — clé inconnue,
        // niveau insuffisant — ne laisse aucune trace, voir le docblock de la classe.
        $enemy = null === $command->enemyKey
            ? $this->enemies->forLevel($progression->level)
            : $this->chosen($command->enemyKey, $progression->level);

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

    /**
     * Résout la clé nommée par le joueur — voir « Le choix de l'adversaire » dans le
     * docblock de la classe pour ce que cette recherche couvre et pourquoi.
     *
     * @throws EnemyKeyUnknown  la clé ne désigne ni un ennemi ni un boss du catalogue
     * @throws EnemyLevelTooLow le joueur n'a pas le niveau — palier ou `minimum_level` — requis
     */
    private function chosen(string $key, int $playerLevel): Enemy
    {
        $enemy = $this->enemies->find($key) ?? $this->enemies->findBoss($key);

        if (null === $enemy) {
            throw new EnemyKeyUnknown($key);
        }

        if ($playerLevel < $enemy->level) {
            throw new EnemyLevelTooLow($key, $enemy->level, $playerLevel);
        }

        return $enemy;
    }
}
