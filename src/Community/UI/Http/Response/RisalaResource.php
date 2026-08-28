<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\RisalaView;
use DateTimeInterface;

/**
 * Une Risāla vivante : le défi, qui l'a envoyé, jusqu'à quand, et **ce que l'appelant y
 * gagne**.
 *
 * `bonusPercent` est résolu côté serveur — 150 ou 50 — et non rendu sous forme de deux taux
 * que le client recomposerait. C'est le serveur qui arbitre les valeurs de jeu ; un écart
 * entre ce que l'écran annonce et ce que le ledger crédite ne se verrait qu'au signalement
 * d'un joueur.
 *
 * L'ordre des champs est celui de la carte : le sport d'abord, qui l'envoie ensuite, le temps
 * qu'il reste, et enfin ce que ça rapporte.
 */
final readonly class RisalaResource
{
    public function __construct(
        public string $id,
        public string $discipline,
        public string $senderId,
        public ?string $senderDisplayName,
        public string $revealedAt,
        public string $expiresAt,
        public int $bonusPercent,
    ) {
    }

    public static function from(RisalaView $risala): self
    {
        return new self(
            $risala->id->toRfc4122(),
            $risala->discipline->value,
            $risala->senderId->toRfc4122(),
            // `null` quand l'expéditeur a quitté la guilde depuis la révélation : son défi
            // reste, son nom n'est plus à afficher comme celui d'un co-équipier.
            $risala->sender?->displayName,
            $risala->revealedAt->format(DateTimeInterface::ATOM),
            $risala->expiresAt->format(DateTimeInterface::ATOM),
            $risala->bonusPercent,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'discipline' => $this->discipline,
            'senderId' => $this->senderId,
            'senderDisplayName' => $this->senderDisplayName,
            'revealedAt' => $this->revealedAt,
            'expiresAt' => $this->expiresAt,
            'bonusPercent' => $this->bonusPercent,
        ];
    }
}
