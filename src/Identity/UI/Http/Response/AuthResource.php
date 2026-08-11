<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Response;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\TokenPair;
use App\Shared\Application\PlayerTitleStanding;
use DateTimeInterface;

/**
 * Réponse d'ouverture de session : le compte et ses jetons. Inscription, login et
 * rafraîchissement renvoient la même forme, pour que le client n'ait qu'un seul
 * chemin de traitement.
 *
 * Les titres en font partie parce que le `user` servi ici est **le même objet** que celui de
 * `GET /api/me` : un client qui décoderait deux formes selon la route devrait rendre ses
 * champs optionnels des deux côtés. Un joueur qui rouvre l'app retrouve donc son titre dans
 * la réponse du login, sans second appel.
 */
final readonly class AuthResource
{
    public function __construct(
        public UserResource $user,
        public TokenPair $tokens,
    ) {
    }

    public static function from(AuthenticatedUser $authenticated, PlayerTitleStanding $titles): self
    {
        return new self(UserResource::from($authenticated->user, $titles), $authenticated->tokens);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user' => $this->user->toArray(),
            'tokens' => [
                'accessToken' => $this->tokens->accessToken,
                'tokenType' => 'Bearer',
                'expiresIn' => $this->tokens->expiresIn,
                'refreshToken' => $this->tokens->refreshToken,
                'refreshTokenExpiresAt' => $this->tokens->refreshTokenExpiresAt->format(DateTimeInterface::ATOM),
            ],
        ];
    }
}
