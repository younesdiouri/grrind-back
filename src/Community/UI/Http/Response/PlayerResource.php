<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Shared\Application\PlayerProfile;
use App\Shared\Application\PlayerProgression;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Un joueur **tel que les autres joueurs le voient**. La seule forme sous laquelle l'API
 * expose quelqu'un d'autre que soi-même.
 *
 * **Ce qui n'y figure pas est la moitié du contrat** : ni adresse, ni fuseau, ni rôle
 * applicatif. Ce sont des données de compte et non de profil public. La garantie ne repose
 * pas sur la vigilance de cette classe : les ports qui l'alimentent ne rendent tout
 * simplement pas ces champs, donc ils ne peuvent pas arriver jusqu'ici par distraction —
 * ce qui reste vrai le jour où quelqu'un ajoutera un champ sans relire ce docblock.
 *
 * Elle sert la liste des membres (#117) et le profil d'un co-équipier (#119) : mêmes ports,
 * même ressource, donc un seul type à décoder et un seul composant à dessiner côté client.
 */
final readonly class PlayerResource
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $registeredAt,
        public int $level,
        public int $xpIntoLevel,
        public ?int $xpToNextLevel,
        /** @var array<string, mixed>|null la forme unique d'un titre dans toute l'API */
        public ?array $title,
    ) {
    }

    public static function from(Uuid $playerId, PlayerProfile $profile, PlayerProgression $progression): self
    {
        return new self(
            $playerId->toRfc4122(),
            $profile->displayName,
            $profile->registeredAt->format(DateTimeInterface::ATOM),
            $progression->level,
            $progression->xpIntoLevel,
            $progression->xpToNextLevel,
            $progression->title?->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'displayName' => $this->displayName,
            'registeredAt' => $this->registeredAt,
            'level' => $this->level,
            'xpIntoLevel' => $this->xpIntoLevel,
            'xpToNextLevel' => $this->xpToNextLevel,
            'title' => $this->title,
        ];
    }
}
