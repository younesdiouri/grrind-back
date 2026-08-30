<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\Exception\EnemyKeyUnknown;
use App\Combat\Domain\Exception\EnemyLevelTooLow;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Application\BattleDrop;
use App\Shared\Application\BattleDrops;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\PlayerProgressions;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * Un combat PvE, de bout en bout : lire le joueur, choisir l'ennemi, jouer, écrire la ligne
 * et sa récompense (#227).
 *
 * ## Le pipeline, dans l'ordre
 *
 * 1. {@see PlayerProgressions} rend la progression du joueur — batch par construction, un
 *    seul élément ici, voir son docblock pour pourquoi ce port et pas un huitième ;
 * 2. {@see FighterFactory} dérive le combattant du joueur, modificateurs équipés compris
 *    depuis #224 — voir son docblock pour l'ordre exact ; l'ennemi vient soit de
 *    {@see EnemyCatalog::forLevel()} — le serveur choisit, comportement inchangé depuis le
 *    #212 — soit de {@see chosen()} quand `$command->enemyKey` est renseigné (#219) ; dans
 *    les deux cas {@see FighterFactory::forEnemy()} le traduit ensuite, même porte que le
 *    joueur, mais sans consulter aucun modificateur — voir le docblock de la factory ;
 * 3. `random_bytes(32)` tire la graine — jamais un hash d'une chaîne, voir le docblock de
 *    {@see Battle} pour le piège que ça a coûté au #209 ;
 * 4. {@see BattleSimulator::fight()} joue le combat, pur, sur les deux combattants et le
 *    `Randomizer` grainé — tout ce qui précède est du calcul, rien de tout ça n'a besoin
 *    d'une transaction ouverte ;
 * 5. **sous une seule transaction** — voir « Le tirage, dans la même transaction que
 *    l'écriture » plus bas — {@see BattleDrops::rollFor()} tire la récompense, puis
 *    {@see Battle::conclude()} écrit la ligne, reward compris, jamais mutée après.
 *
 * **`$this->clock->now()` n'est appelée qu'une fois** (#224), au tout début, et sert à la
 * fois de date pour {@see \App\Shared\Application\ModifierResolver} — via `FighterFactory`
 * puis via `BattleDrops` pour `LOOT_LUCK` — et de `$foughtAt` : un combat a lieu à l'instant
 * de la requête, contrairement à un workout, et les trois usages doivent parler du même
 * instant plutôt que de plusieurs appels d'horloge qui pourraient diverger d'une
 * milliseconde.
 *
 * ## Le tirage, dans la même transaction que l'écriture (#227)
 *
 * Le geste reproduit est celui de `ImportWorkoutsHandler` et `GrantXpHandler`, pas un
 * troisième inventé pour l'occasion : {@see BattleRepository::transactional()} ouvre la
 * transaction, et chaque écriture de `Rewards` sous elle (`LootRoll`, `Inventory`,
 * `CoinLedger`) rouvre son propre `wrapInTransaction` — DBAL en fait un point de sauvegarde,
 * jamais une seconde transaction réelle. Un combat gagné dont le loot n'est pas écrit serait
 * une perte silencieuse ; un loot écrit sans son combat, un objet sans provenance.
 *
 * `$id` est tiré **avant** d'ouvrir la transaction, parce que {@see BattleDrops::rollFor()}
 * en a besoin comme `causeId` de son tirage avant que la ligne `Battle` existe — voir
 * « `$id` est fourni par l'appelant » dans le docblock de `Battle` pour pourquoi c'est ici,
 * et pas dans le constructeur de `Battle`, que ça se décide.
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
 * ## La récompense d'une victoire, et rien ne borne le farm en V1 (#227)
 *
 * **Seule une victoire rapporte.** `BattleResult::Victory === $outcome->result` est calculé
 * une fois ici et traverse en booléen jusqu'à {@see BattleDrops::rollFor()} — voir son
 * docblock pour pourquoi jamais `BattleResult` lui-même. Une défaite, ou une victoire
 * tranchée par `max_turns` sans KO, ne rapportent ni objet ni pièce : une récompense de
 * consolation ferait du combat perdu la stratégie optimale, puisqu'il est plus rapide à
 * jouer qu'à gagner.
 *
 * **Rien ne borne le farm en V1, et c'est une décision, pas un oubli.** Pas de cooldown,
 * pas de première-victoire-seulement, pas de plafond quotidien, pas de jeton de combat : un
 * joueur peut rejouer le même adversaire en boucle et empiler les pièces — y compris un
 * ennemi d'un palier très inférieur au sien, effet de bord du #219 resté sans conséquence
 * tant qu'un combat ne rapportait rien, et qui en a une maintenant. C'est assumé pour la
 * phase de dev (voir `CLAUDE.md`) : personne ne joue encore, aucune économie n'est calibrée,
 * et choisir un garde-fou maintenant reviendrait à équilibrer contre une intuition plutôt
 * que contre des chiffres. Le ticket de dette #228 le posera, sur des chiffres, avant le
 * premier joueur réel.
 */
final readonly class FightBattleHandler
{
    public function __construct(
        private PlayerProgressions $progressions,
        private FighterFactory $fighters,
        private EnemyCatalog $enemies,
        private BattleSimulator $simulator,
        private BattleRepository $battles,
        private BattleDrops $drops,
        private string $rulesetVersion,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(FightBattle $command): Battle
    {
        // Un seul appel d'horloge, réutilisé pour le resolver de modificateurs et pour
        // `$foughtAt` : les deux doivent parler du même instant, celui de cette requête —
        // voir le docblock de `FighterFactory` et celui de `Battle::$foughtAt`.
        $now = $this->clock->now();

        $progressions = $this->progressions->of([$command->playerId]);
        $progression = $progressions[$command->playerId->toRfc4122()];

        $player = $this->fighters->forPlayer($progression, $command->playerId, $now);

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

        // Tiré avant la transaction — voir « Le tirage, dans la même transaction que
        // l'écriture » dans le docblock de la classe pour pourquoi `BattleDrops` en a
        // besoin avant que la ligne existe.
        $id = Uuid::v7();
        $victory = BattleResult::Victory === $outcome->result;

        return $this->battles->transactional(function () use (
            $id,
            $command,
            $progression,
            $player,
            $enemy,
            $enemyFighter,
            $outcome,
            $victory,
            $seed,
            $now,
        ): Battle {
            $drop = $this->drops->rollFor($command->playerId, $enemy->key, $victory, $id, $now);

            $battle = Battle::conclude(
                $id,
                $command->playerId,
                $progression->attributes,
                $progression->vitality,
                $player,
                $enemy,
                $enemyFighter,
                $outcome,
                self::rewardToArray($drop),
                $seed,
                $this->rulesetVersion,
                $now,
            );

            $this->battles->add($battle);
            $this->battles->commit();

            return $battle;
        });
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

    /**
     * La forme persistée sur `Battle::$reward` — voir son docblock. Les objets d'abord,
     * déjà sérialisés par `Rewards` via {@see DroppedItem::toArray()}, puis les pièces en
     * `{gained, before, after}` : le même ordre et les mêmes clés que `RewardSummary` rend
     * déjà pour un drop de séance, pour que le client réutilise le composant qu'il a écrit.
     *
     * @return array{loot: list<array<string, mixed>>, coins: array{gained: int, before: int, after: int}}
     */
    private static function rewardToArray(BattleDrop $drop): array
    {
        return [
            'loot' => array_map(static fn (DroppedItem $item): array => $item->toArray(), $drop->items),
            'coins' => [
                'gained' => $drop->coinsGained,
                'before' => $drop->coinsBefore,
                'after' => $drop->coinsAfter,
            ],
        ];
    }
}
