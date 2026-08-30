<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * L'unique porte d'écriture des pièces d'un joueur. **Personne en dehors de `Rewards` n'en
 * crédite** — ni un port de `Shared`, qui n'a ici aucun consommateur à justifier, ni un
 * accès direct à {@see CoinTransactionRepository}. Le #226 (import) et le #227 (combat)
 * traverseront cette classe chacun par **son** port, défini dans son propre module :
 * ce n'est pas à ce ticket de deviner leur forme.
 *
 * Le calcul du montant n'a pas sa place ici : il vient déjà décidé de
 * {@see \App\Rewards\Domain\LootRoller}, exactement comme {@see \App\Progression\Application\GrantXp}
 * ne recalcule pas l'XP qu'on lui passe. Cette classe ne fait qu'écrire, sous la garantie
 * que porte {@see CoinTransactionRepository::record()}.
 */
final readonly class CoinLedger
{
    public function __construct(
        private CoinTransactionRepository $transactions,
    ) {
    }

    /**
     * Crédite un joueur pour un drop de séance ou de combat.
     *
     * `$amount` est strictement positif : un crédit qui retirerait des pièces n'en serait
     * plus un, et la seule raison qui écrira demain un montant négatif — `PURCHASE`, au Lot
     * 6b — passera par sa propre méthode plutôt que de détourner celle-ci.
     */
    public function credit(Uuid $userId, CoinReason $reason, Uuid $sourceId, int $amount, DateTimeImmutable $occurredAt): void
    {
        \assert($amount > 0, 'Un crédit de pièces doit être strictement positif — voir CoinReason.');

        $this->transactions->record($userId, $reason, $sourceId, $amount, $occurredAt);
    }

    /** Le solde d'un joueur — voir le docblock de {@see CoinTransactionRepository::balanceOf()}. */
    public function balanceOf(Uuid $userId): int
    {
        return $this->transactions->balanceOf($userId);
    }
}
