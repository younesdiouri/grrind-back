<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un combat PvE joué et jugé, tel qu'il reste consultable après coup (#211).
 *
 * ## On stocke la timeline, **et** la graine
 *
 * Le réflexe serait de ne garder que la graine et de rejouer le combat à la demande.
 * C'est faux ici, pour la raison exacte qui fait que {@see \App\Progression\Domain\XpTransaction}
 * stocke le montant *et* la version du ruleset *et* le détail du calcul : le jour où
 * `combat.yaml` est rééquilibré, rejouer une vieille graine sous les règles courantes
 * produirait un **autre** combat que celui que le joueur a regardé. L'historique mentirait.
 *
 * La `$timeline` est donc la vérité de ce qui a été montré ; `$seed` et les deux snapshots
 * ne servent qu'à l'**audit** — démontrer que cette timeline sort bien de ces entrées, ce
 * que la timeline seule ne prouve pas.
 *
 * ## `$foughtAt` vient de l'horloge serveur, et c'est légitime ici
 *
 * Contrairement à un workout — un fait passé que le serveur ne fait qu'arbitrer, sur des
 * bornes qui viennent du fournisseur santé — un combat **a lieu à l'instant de la
 * requête** : il n'existe aucune source antérieure au serveur qui pourrait le dater. C'est
 * le seul endroit du module où `ClockInterface` est la bonne source, et il ne faut pas
 * « corriger » ce choix dans le mauvais sens le jour où quelqu'un le relira à côté d'un
 * workout.
 *
 * ## Pas de clé étrangère vers le compte
 *
 * `$playerId` est un UUID nu, comme partout dans `Community` — voir
 * {@see \App\Community\Domain\GuildMembership}. Deptrac interdit à `Combat` de connaître
 * `Identity`, et la base suit le même découpage.
 *
 * ## La graine se stocke en hexadécimal
 *
 * `Random\Engine\Xoshiro256StarStar` exige exactement 32 octets, tirés par `random_bytes(32)`
 * dans {@see \App\Combat\Application\FightBattleHandler}. Cette classe les convertit en 64
 * caractères hexadécimaux avant de les stocker : c'est la seule représentation qui **revient
 * à l'identique** par `hex2bin()`/`bin2hex()` — contrairement au binaire brut, illisible dans
 * un `psql` (échappé en octal), ou au base64, qui ne s'audite pas à l'œil. Un octet de graine
 * s'y lit comme deux caractères, sans ambiguïté.
 *
 * ## La timeline et les snapshots : une forme explicite, jamais un cast d'objet
 *
 * Les cinq formes de {@see BattleEvent} et les deux `Fighter` snapshotés se sérialisent
 * par un mapping écrit à la main ({@see eventToArray()}, {@see fighterToArray()}), avec des
 * clés nommées dans un ordre fixe. Jamais un `(array)` sur un objet ou un `json_encode`
 * implicite : l'un dépend de l'ordre de déclaration des propriétés PHP — qui bouge sans
 * prévenir au moindre refactor — et ni l'un ni l'autre ne garantit un nom de clé stable.
 * {@see \App\Tests\Combat\Domain\BattleTest} fige cette forme.
 *
 * **PostgreSQL réordonne lui-même les clés d'un objet JSONB au stockage** — par longueur
 * puis ordre alphabétique, pas par ordre d'écriture. Une ligne relue après un aller-retour
 * en base ne rend donc pas ses objets dans l'ordre où {@see eventToArray()} les a écrits.
 * Ce n'est pas un bug à corriger : un objet JSON n'a pas d'ordre de clé signifiant, et c'est
 * précisément pour ça que le format retenu identifie chaque champ par son **nom** plutôt que
 * par sa position. Seul l'ordre des **éléments de la liste** `$timeline` compte — c'est lui
 * l'ordre de l'animation — et celui-là, PostgreSQL le préserve.
 */
#[ORM\Entity(repositoryClass: BattleRepository::class)]
#[ORM\Table(name: 'combat_battle')]
// Sert `BattleRepository::history()` (#220) : `player_id` d'abord pour filtrer, puis l'ordre
// exact du tri de la pagination pour que Postgres n'ait rien à trier à part — voir la
// migration qui remplace cet index et son docblock pour pourquoi un `DROP` + `CREATE` plutôt
// qu'un `ALTER`.
#[ORM\Index(name: 'idx_combat_battle_player', columns: ['player_id', 'fought_at', 'id'])]
class Battle
{
    /** `Xoshiro256StarStar` exige exactement cette taille — voir le docblock de la classe. */
    private const int SEED_LENGTH_BYTES = 32;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $playerId;

    /**
     * Les quatre caractéristiques, la Vitality, et le `Fighter` qui en a été dérivé — voir
     * {@see playerSnapshotOf()}.
     *
     * @var array{attributes: array{strength: int, endurance: int, mobility: int, dexterity: int}, vitality: int, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $playerSnapshot;

    /**
     * La clé de l'ennemi du catalogue, et son `Fighter` au moment du combat — voir
     * {@see enemySnapshotOf()}.
     *
     * @var array{key: string, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $enemySnapshot;

    #[ORM\Column(length: 16, enumType: BattleResult::class)]
    private BattleResult $result;

    /**
     * La liste ordonnée des événements — le contrat d'animation, voir {@see BattleEvent}.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $timeline;

    /** 64 caractères hexadécimaux : voir le docblock de la classe pour ce choix. */
    #[ORM\Column(length: 64)]
    private string $seed;

    /**
     * Le hash du `GameBalance` chargé — même geste et même colonne que
     * {@see \App\Progression\Domain\XpTransaction} : ce combat vaut ce qu'il valait sous
     * les règles qui l'ont produit, pas sous celles d'aujourd'hui.
     */
    #[ORM\Column(length: 32)]
    private string $rulesetVersion;

    /**
     * Pour lister un historique sans désérialiser `$timeline` — le nombre de tours joués,
     * dérivable du dernier événement mais porté ici pour ne pas payer ce coût à l'affichage.
     */
    #[ORM\Column]
    private int $turns;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $foughtAt;

    /**
     * @param array{attributes: array{strength: int, endurance: int, mobility: int, dexterity: int}, vitality: int, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}} $playerSnapshot
     * @param array{key: string, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}                                                                                    $enemySnapshot
     */
    private function __construct(
        Uuid $playerId,
        array $playerSnapshot,
        array $enemySnapshot,
        BattleOutcome $outcome,
        string $seed,
        string $rulesetVersion,
        DateTimeImmutable $foughtAt,
    ) {
        if (self::SEED_LENGTH_BYTES !== \strlen($seed)) {
            throw new InvalidArgumentException(\sprintf('La graine d\'un combat doit faire %d octets, %d reçus.', self::SEED_LENGTH_BYTES, \strlen($seed)));
        }

        $this->id = Uuid::v7();
        $this->playerId = $playerId;
        $this->playerSnapshot = $playerSnapshot;
        $this->enemySnapshot = $enemySnapshot;
        $this->result = $outcome->result;
        $this->timeline = array_map(self::eventToArray(...), $outcome->timeline);
        $this->seed = bin2hex($seed);
        $this->rulesetVersion = $rulesetVersion;
        $this->turns = $outcome->turns;
        $this->foughtAt = $foughtAt;
    }

    /**
     * Le seul point de construction — voir le docblock de la classe : jamais mutée après.
     *
     * `$seed` est la graine **brute**, telle que tirée par `random_bytes(32)` dans
     * {@see \App\Combat\Application\FightBattleHandler} — pas encore convertie en
     * hexadécimal, ce que fait cette classe.
     */
    public static function conclude(
        Uuid $playerId,
        AttributeGains $playerAttributes,
        int $playerVitality,
        Fighter $playerFighter,
        Enemy $enemy,
        Fighter $enemyFighter,
        BattleOutcome $outcome,
        string $seed,
        string $rulesetVersion,
        DateTimeImmutable $foughtAt,
    ): self {
        return new self(
            $playerId,
            self::playerSnapshotOf($playerAttributes, $playerVitality, $playerFighter),
            self::enemySnapshotOf($enemy, $enemyFighter),
            $outcome,
            $seed,
            $rulesetVersion,
            $foughtAt,
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function playerId(): Uuid
    {
        return $this->playerId;
    }

    /**
     * @return array{attributes: array{strength: int, endurance: int, mobility: int, dexterity: int}, vitality: int, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    public function playerSnapshot(): array
    {
        return $this->playerSnapshot;
    }

    /**
     * @return array{key: string, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    public function enemySnapshot(): array
    {
        return $this->enemySnapshot;
    }

    public function result(): BattleResult
    {
        return $this->result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(): array
    {
        return $this->timeline;
    }

    /** 64 caractères hexadécimaux — voir le docblock de la classe. */
    public function seed(): string
    {
        return $this->seed;
    }

    public function rulesetVersion(): string
    {
        return $this->rulesetVersion;
    }

    public function turns(): int
    {
        return $this->turns;
    }

    public function foughtAt(): DateTimeImmutable
    {
        return $this->foughtAt;
    }

    /**
     * @return array{attributes: array{strength: int, endurance: int, mobility: int, dexterity: int}, vitality: int, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    private static function playerSnapshotOf(AttributeGains $attributes, int $vitality, Fighter $fighter): array
    {
        return [
            'attributes' => $attributes->toArray(),
            'vitality' => $vitality,
            'fighter' => self::fighterToArray($fighter),
        ];
    }

    /**
     * @return array{key: string, fighter: array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}}
     */
    private static function enemySnapshotOf(Enemy $enemy, Fighter $fighter): array
    {
        return [
            'key' => $enemy->key,
            'fighter' => self::fighterToArray($fighter),
        ];
    }

    /**
     * @return array{hp: int, damage: int, mitigationPermille: int, extraTurnPermille: int, dodgePermille: int}
     */
    private static function fighterToArray(Fighter $fighter): array
    {
        return [
            'hp' => $fighter->hp,
            'damage' => $fighter->damage,
            'mitigationPermille' => $fighter->mitigationPermille,
            'extraTurnPermille' => $fighter->extraTurnPermille,
            'dodgePermille' => $fighter->dodgePermille,
        ];
    }

    /**
     * Le mapping écrit à la main qui fige la forme d'un événement — voir le docblock de la
     * classe pour pourquoi ni un cast ni un `json_encode` implicite ne conviennent.
     *
     * @return array<string, mixed>
     */
    private static function eventToArray(BattleEvent $event): array
    {
        return match (true) {
            $event instanceof BattleStarted => [
                'type' => 'BATTLE_STARTED',
                'playerHp' => $event->playerHp,
                'enemyHp' => $event->enemyHp,
            ],
            $event instanceof Attack => [
                'type' => 'ATTACK',
                'attacker' => $event->attacker->value,
                'damage' => $event->damage,
                'mitigated' => $event->mitigated,
                'targetHpRemaining' => $event->targetHpRemaining,
            ],
            $event instanceof Dodge => [
                'type' => 'DODGE',
                'attacker' => $event->attacker->value,
            ],
            $event instanceof ExtraTurn => [
                'type' => 'EXTRA_TURN',
                'actor' => $event->actor->value,
            ],
            $event instanceof BattleFinished => [
                'type' => 'BATTLE_FINISHED',
                'result' => $event->result->value,
            ],
            // Fermé aux cinq formes de BattleEvent (#218 en ajoute une) : une sixième qui
            // arriverait sans mapping ici doit casser tout de suite, pas s'écrire
            // silencieusement en JSONB amputée de ses champs.
            default => throw new LogicException(\sprintf('Aucune forme de sérialisation pour un événement de combat "%s".', $event::class)),
        };
    }
}
