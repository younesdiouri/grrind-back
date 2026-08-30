<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Rewards\Infrastructure\Doctrine\LootRollRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La ligne d'audit d'un tirage — ce que {@see LootRoller} a produit, gelé pour toujours.
 * **Le RNG est serveur et auditable** : sans cette ligne, aucun litige de joueur n'est
 * arbitrable et aucun bug d'équilibrage n'est reproductible, voir le ticket #28.
 *
 * ## Append-only, comme `Battle` et `XpTransaction`
 *
 * Un tirage ne se corrige ni ne se rejoue par-dessus : il a eu lieu, avec ces poids, sous
 * cette version de table, et la ligne le dit pour toujours. Aucun mutateur, aucune méthode
 * qui change quoi que ce soit après {@see record()}.
 *
 * ## Pourquoi `$effectiveLootLuckPercent` est stocké, et pas seulement `$tableVersion`
 *
 * `tableVersion` dit sous quelles pondérations *déclarées* ce tirage a eu lieu ; il ne dit
 * rien de l'équipement porté par le joueur ce jour-là, qui a pu changer depuis — voir le
 * docblock de `LootRoller` pour comment `LOOT_LUCK` déplace les poids. Sans ce champ,
 * rejouer ce tirage six mois plus tard obligerait à reconstituer l'inventaire équipé
 * exactement à cet instant, alors que rien d'autre dans le jeu ne demande de remonter aussi
 * loin dans un historique qui n'existe pas encore (#29). Le stocker ici est ce qui rend la
 * ligne auto-suffisante, même geste que `discipline`/`durationSeconds` sur `XpTransaction`.
 *
 * ## `$roll` et `$result` : le tirage brut, et ce que le joueur a vu
 *
 * `$roll` porte le nombre tiré pour choisir une entrée (`itemRoll`) et la somme des poids
 * qui lui donnait son sens (`itemTotalWeight`) — voir le docblock de {@see LootRollOutcome}
 * pour pourquoi les pièces n'y ont pas leur pendant : leur tirage *est* déjà leur résultat.
 * `$result` porte ce que la mise en scène montre : les objets (éventuellement aucun) et le
 * montant de pièces. Les deux sont nécessaires ensemble — `$result` seul ne prouve pas que
 * le tirage était honnête, `$roll` seul ne dit pas ce que le joueur a reçu.
 *
 * ## La graine se stocke en hexadécimal
 *
 * Même choix et mêmes raisons qu'{@see \App\Combat\Domain\Battle::$seed} : 64 caractères
 * hexadécimaux, `bin2hex()`/`hex2bin()`, la seule représentation qui revient à l'identique
 * et qui s'audite à l'œil dans un `psql` — jamais le binaire brut, jamais du base64.
 *
 * ## Pas de clé étrangère vers le compte, ni vers ce qui a causé le tirage
 *
 * `$userId` est un UUID nu, comme partout dans `Community` et dans `Combat` — voir le
 * docblock d'{@see \App\Combat\Domain\Battle}. `$causeId` — l'identifiant du workout ou du
 * combat à l'origine du tirage — l'est tout autant : `Rewards` ne connaît ni `Training` ni
 * `Combat`, et Deptrac l'interdirait même si la base le permettait.
 */
#[ORM\Entity(repositoryClass: LootRollRepository::class)]
#[ORM\Table(name: 'rewards_loot_roll')]
// Un tirage se retrouve par le joueur qui l'a reçu, du plus récent au plus ancien — le même
// besoin qu'un futur historique d'inventaire (#29) aura, avant même qu'il existe.
#[ORM\Index(name: 'idx_rewards_loot_roll_user', columns: ['user_id', 'rolled_at'])]
class LootRoll
{
    /** `Xoshiro256StarStar` exige exactement cette taille — voir le docblock de la classe. */
    private const int SEED_LENGTH_BYTES = 32;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column(length: 16, enumType: LootRollOrigin::class)]
    private LootRollOrigin $origin;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $causeId;

    /** 64 caractères hexadécimaux : voir le docblock de la classe pour ce choix. */
    #[ORM\Column(length: 64)]
    private string $seed;

    #[ORM\Column(length: 64)]
    private string $tableKey;

    #[ORM\Column]
    private int $tableVersion;

    #[ORM\Column]
    private int $effectiveLootLuckPercent;

    /**
     * @var array{itemRoll: int, itemTotalWeight: int}
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $roll;

    /**
     * @var array{items: list<string>, coins: int}
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $result;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $rolledAt;

    private function __construct(
        Uuid $userId,
        LootRollOrigin $origin,
        Uuid $causeId,
        string $seed,
        LootRollOutcome $outcome,
        DateTimeImmutable $rolledAt,
    ) {
        if (self::SEED_LENGTH_BYTES !== \strlen($seed)) {
            throw new InvalidArgumentException(\sprintf('La graine d\'un tirage doit faire %d octets, %d reçus.', self::SEED_LENGTH_BYTES, \strlen($seed)));
        }

        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->origin = $origin;
        $this->causeId = $causeId;
        $this->seed = bin2hex($seed);
        $this->tableKey = $outcome->tableKey;
        $this->tableVersion = $outcome->tableVersion;
        $this->effectiveLootLuckPercent = $outcome->effectiveLootLuckPercent;
        $this->roll = [
            'itemRoll' => $outcome->itemRoll,
            'itemTotalWeight' => $outcome->itemTotalWeight,
        ];
        $this->result = [
            'items' => $outcome->items,
            'coins' => $outcome->coins,
        ];
        $this->rolledAt = $rolledAt;
    }

    /**
     * Le seul point de construction — jamais mutée après, voir le docblock de la classe.
     *
     * `$seed` est la graine **brute**, telle que tirée par `random_bytes(32)` chez
     * l'appelant — pas encore convertie en hexadécimal, ce que fait cette classe.
     */
    public static function record(
        Uuid $userId,
        LootRollOrigin $origin,
        Uuid $causeId,
        string $seed,
        LootRollOutcome $outcome,
        DateTimeImmutable $rolledAt,
    ): self {
        return new self($userId, $origin, $causeId, $seed, $outcome, $rolledAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function origin(): LootRollOrigin
    {
        return $this->origin;
    }

    public function causeId(): Uuid
    {
        return $this->causeId;
    }

    /** 64 caractères hexadécimaux — voir le docblock de la classe. */
    public function seed(): string
    {
        return $this->seed;
    }

    public function tableKey(): string
    {
        return $this->tableKey;
    }

    public function tableVersion(): int
    {
        return $this->tableVersion;
    }

    public function effectiveLootLuckPercent(): int
    {
        return $this->effectiveLootLuckPercent;
    }

    /**
     * @return array{itemRoll: int, itemTotalWeight: int}
     */
    public function roll(): array
    {
        return $this->roll;
    }

    /**
     * @return array{items: list<string>, coins: int}
     */
    public function result(): array
    {
        return $this->result;
    }

    public function rolledAt(): DateTimeImmutable
    {
        return $this->rolledAt;
    }
}
