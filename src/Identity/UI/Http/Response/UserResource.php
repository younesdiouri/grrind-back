<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Response;

use App\Identity\Domain\User;
use App\Shared\Application\PlayerTitleStanding;
use DateTimeInterface;

/**
 * Représentation publique d'un compte. Séparée de l'entité pour que le jour où
 * celle-ci gagne un champ, il ne parte pas sur le réseau par accident.
 *
 * Les titres n'en font pas partie : ils appartiennent à `Progression` et arrivent par le
 * port {@see PlayerTitleStanding}, déjà traduits. Ils sont ici plutôt que sur leur propre
 * route parce que le client les affiche **à côté du pseudo**, dans le même écran et dès
 * l'ouverture de l'app — les séparer coûterait un aller-retour pour une ligne de texte.
 */
final readonly class UserResource
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public string $timezone,
        public string $registeredAt,
        public PlayerTitleStanding $titles,
    ) {
    }

    public static function from(User $user, PlayerTitleStanding $titles): self
    {
        return new self(
            $user->id()->toRfc4122(),
            $user->email(),
            $user->displayName(),
            $user->timezone()->toString(),
            $user->registeredAt()->format(DateTimeInterface::ATOM),
            $titles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'timezone' => $this->timezone,
            'registeredAt' => $this->registeredAt,
            // Deux clés distinctes et non une liste : le client en affiche une comme un
            // acquis et l'autre comme un objectif, à deux endroits différents de l'écran.
            'title' => $this->titles->active?->toArray(),
            'nextTitle' => $this->titles->next?->toArray(),
        ];
    }
}
