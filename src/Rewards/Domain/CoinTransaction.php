<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une écriture au ledger de pièces — le même geste que
 * {@see \App\Progression\Domain\XpTransaction}, en beaucoup plus simple : pas de breakdown,
 * pas de répartition par caractéristique, une seule table plutôt que deux. `amount` est
 * signé et **est** la valeur de l'écriture, il n'y a rien d'autre à recomposer.
 *
 * **Append-only, sans garde-fou Doctrine dédié** — contrairement à `XpTransaction`, qui en
 * a un ({@see \App\Progression\Infrastructure\Doctrine\LedgerIsAppendOnly}) parce qu'elle
 * porte une collection de lignes filles, surface où une désérialisation ou une cascade mal
 * placée peut introduire un `UPDATE`. Cette classe n'a ni mutateur ni collection : le même
 * risque n'existe pas, et {@see LootRoll} — son voisin le plus proche
 * dans ce module, une ligne d'audit tout aussi figée — fait le même choix pour la même
 * raison.
 *
 * **Le solde n'est pas une colonne.** Il n'y a pas de projection façon
 * `progression_snapshot` en v1 : la ligne d'index `idx_rewards_coin_transaction_user_id`
 * sur `(user_id, occurred_at, id)` sert `user_id` en tête pour que la somme de
 * {@see CoinTransactionRepository::balanceOf()} filtre par index plutôt que de balayer la
 * table entière. Un cache s'ajoutera le jour où on mesurera qu'il manque, et il sera
 * reconstructible par cette même somme — exactement le rapport que `progression_snapshot`
 * entretient avec `xp_transaction`.
 *
 * **Pas de clé étrangère**, ni vers le compte ni vers ce qui a causé l'écriture : même
 * choix et mêmes raisons que sur `LootRoll` — `Rewards` ne connaît ni `Identity`, ni
 * `Training`, ni `Combat`, et Deptrac l'interdirait même si la base le permettait.
 */
#[ORM\Entity(repositoryClass: CoinTransactionRepository::class)]
#[ORM\Table(name: 'rewards_coin_transaction')]
// `(user_id, occurred_at, id)`, remplace le `(user_id, id)` du #225 — même correction que
// `Version20260829160000` sur `idx_combat_battle_player` : le tri de
// `GET /api/inventory/coins` (#30) porte sur la date du **fait**, jamais sur l'ordre
// d'écriture, voir le docblock de `CoinTransactionRepository::history()`. Un import qui
// remonte un workout vieux de dix jours doit ranger sa pièce dix jours en arrière, à côté
// de la ligne d'XP du même workout — deux écrans qui trieraient différemment le même
// import ne se recouperaient plus. `user_id` en tête sert toujours `balanceOf()` : la
// somme n'a besoin que de filtrer par joueur, les deux colonnes suivantes ne lui coûtent
// rien.
#[ORM\Index(name: 'idx_rewards_coin_transaction_user_id', columns: ['user_id', 'occurred_at', 'id'])]
class CoinTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** Entier signé. Jamais un flottant : une valeur de jeu persistée ne s'arrondit pas. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 16, enumType: CoinReason::class)]
    private CoinReason $reason;

    /**
     * Ce qui a produit l'écriture — l'identifiant du workout ou du combat en v1. Pas de
     * clé étrangère, voir le docblock de la classe.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $sourceId;

    /**
     * **L'instant du fait, pas celui de l'écriture** — la date du sport pour un drop de
     * séance, l'instant de la requête pour un combat. Même raison qu'à
     * {@see \App\Progression\Domain\XpTransaction::$occurredAt} : dix workouts importés
     * d'un coup appartiennent à dix journées différentes, et rien ici n'a de plafond
     * quotidien à leur appliquer — mais un futur relevé « combien de pièces le tel jour »
     * doit pouvoir compter sur cette date-là, pas sur celle de la synchronisation qui les a
     * remontés. L'instant de l'écriture n'est pas perdu : il est dans l'UUID v7 de la ligne.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    private function __construct(Uuid $userId, CoinReason $reason, Uuid $sourceId, int $amount, DateTimeImmutable $occurredAt)
    {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->reason = $reason;
        $this->sourceId = $sourceId;
        $this->amount = $amount;
        $this->occurredAt = $occurredAt;
    }

    /**
     * Le seul point de construction. **Neutre sur le signe** : une raison ne décide pas
     * elle-même si `$amount` est positif ou négatif — `WORKOUT_DROP` et `BATTLE_DROP` ne
     * produisent aujourd'hui que des montants positifs (voir
     * {@see \App\Rewards\Application\CoinLedger::credit()}, seul appelant), mais l'entité
     * ne le suppose pas : `PURCHASE` écrira demain une ligne négative avec la même
     * fabrique, et {@see CoinTransactionRepository::record()}
     * est déjà le garde-fou générique qui refuse un solde négatif, quel que soit le signe
     * en entrée.
     */
    public static function record(Uuid $userId, CoinReason $reason, Uuid $sourceId, int $amount, DateTimeImmutable $occurredAt): self
    {
        return new self($userId, $reason, $sourceId, $amount, $occurredAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function reason(): CoinReason
    {
        return $this->reason;
    }

    public function sourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
