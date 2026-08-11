<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Un titre, tel que le client le reçoit : traduit, situé, daté s'il est acquis.
 *
 * **C'est la seule forme JSON d'un titre dans toute l'API.** `GET /api/me` et
 * `GET /api/titles` la servent tous les deux, débloqué comme verrouillé, ce qui laisse à
 * l'app iOS un unique type à décoder et un unique composant à dessiner. C'est aussi pour ça
 * que la mise en forme vit ici plutôt que dans une ressource HTTP par module : deux
 * ressources finiraient par diverger d'un champ, et le client par avoir deux structures.
 *
 * `progress` est renseignée même sur un titre acquis — `current` y vaut alors `target`. Un
 * champ qui disparaît selon l'état est un champ que le client doit rendre optionnel, et
 * c'est une complication pour rien.
 */
final readonly class PlayerTitle
{
    public function __construct(
        public string $id,
        public string $name,
        /** Ce qu'il faut faire pour l'obtenir, déjà rédigé dans la langue du joueur. */
        public string $hint,
        /** `null` tant qu'il n'est pas débloqué. C'est ce qui distingue les deux états. */
        public ?DateTimeImmutable $unlockedAt,
        public int $current,
        public int $target,
        /** Valeur de `App\Progression\Domain\ProgressUnit` — `Shared` ne connaît pas le module qui la produit. */
        public string $unit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hint' => $this->hint,
            'unlocked' => null !== $this->unlockedAt,
            'unlockedAt' => $this->unlockedAt?->format(DateTimeInterface::ATOM),
            'progress' => [
                'current' => $this->current,
                'target' => $this->target,
                'unit' => $this->unit,
            ],
        ];
    }
}
