<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Shared\Application\PlayerProfile;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
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
 * **Les cinq caractéristiques (#176) y figurent par décision de produit, pas par fuite.** La
 * répartition d'une pratique a été tranchée sociale — c'est une des raisons d'avoir des
 * guildes — et {@see PlayerProgression} les porte désormais pour ça, en amont de cette
 * classe. Ce que la liste ci-dessus continue d'exclure ne change pas d'un mot : le jour où un
 * nouveau champ voudra entrer ici, c'est *lui* qui doit répondre à la même question, pas ce
 * docblock qui doit s'assouplir pour lui faire de la place.
 *
 * Elle sert la liste des membres (#117) et le profil d'un co-équipier (#119) : mêmes ports,
 * même ressource, donc un seul type à décoder et un seul composant à dessiner côté client.
 *
 * **`vitality` est bonifiée (#165), `vitalityBreakdown` l'explique** — même vocabulaire et
 * même forme qu'à `GET /api/progression` (`ProgressionResource`), voir son docblock.
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
        /** L'état courant des quatre caractéristiques — même vocabulaire qu'à `GET /api/progression`. */
        public AttributeGains $attributes,
        public int $vitality,
        public VitalityBreakdown $vitalityBreakdown,
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
            $progression->attributes,
            $progression->vitality,
            $progression->vitalityBreakdown,
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
            // Un état, jamais un passage — même forme qu'à `GET /api/progression` (#163) :
            // ni `gained` ni avant/après, seulement la valeur courante. Voir le docblock de
            // `ProgressionResource` pour pourquoi c'est la forme, et non le vocabulaire, qui
            // diffère légitimement du `RewardSummary`.
            'attributes' => [
                'strength' => $this->attributes->strength,
                'endurance' => $this->attributes->endurance,
                'mobility' => $this->attributes->mobility,
                'dexterity' => $this->attributes->dexterity,
                'vitality' => $this->vitality,
            ],
            // Ce qui explique le champ juste au-dessus — voir le docblock de la classe.
            'vitalityBreakdown' => [
                'windowAverageActiveKcal' => $this->vitalityBreakdown->windowAverageActiveKcal,
                'targetActiveKcal' => $this->vitalityBreakdown->targetActiveKcal,
                'bonusPermille' => $this->vitalityBreakdown->bonusPermille,
            ],
        ];
    }
}
