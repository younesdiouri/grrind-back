<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Domain\GuildInviteCode;
use DateTimeInterface;

/**
 * Le code et sa date de péremption.
 *
 * `expiresAt` et non une durée restante : le client affiche « valable jusqu'à demain
 * 18 h », et une durée en secondes se périmerait dans la réponse elle-même — l'écran reste
 * ouvert, la valeur ne se met pas à jour toute seule.
 *
 * Rien sur la révocation : un code révoqué ne se rend jamais.
 */
final readonly class InviteCodeResource
{
    public function __construct(
        public string $code,
        public string $expiresAt,
    ) {
    }

    public static function from(GuildInviteCode $code): self
    {
        return new self($code->code(), $code->expiresAt()->format(DateTimeInterface::ATOM));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
