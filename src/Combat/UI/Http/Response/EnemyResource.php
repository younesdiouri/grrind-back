<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Enemy;
use App\Combat\Infrastructure\Translation\EnemyTranslator;

/**
 * Une entrée du catalogue, telle que `GET /api/enemies` la rend — boss et ennemi ordinaire
 * sous la même forme (#219), puisque le corps de `POST /api/battles` les choisit tous les
 * deux de la même façon, voir le docblock de
 * {@see \App\Combat\Application\FightBattleHandler}.
 *
 * `minimumLevel` est `Enemy::$level`, quelle que soit la liste d'où vient l'entrée : le
 * palier auquel `EnemyCatalog::forLevel()` choisirait un ennemi tout seul fait déjà office
 * de niveau minimum pour le choisir explicitement — voir le docblock d'`EnemyCatalog` — donc
 * un seul nom de champ suffit côté contrat, là où le YAML en porte deux
 * (`level` / `minimum_level`) pour des raisons de lisibilité de la config.
 */
final readonly class EnemyResource
{
    /** @param array{idle: string, attack: string, hit: string}|null $imageUrls */
    private function __construct(
        private Enemy $enemy,
        private string $name,
        private ?array $imageUrls,
        private ?string $introduction,
    ) {
    }

    public static function from(Enemy $enemy, EnemyTranslator $translator): self
    {
        return new self($enemy, $translator->nameOf($enemy->key), $translator->imageUrlsOf($enemy->key), $translator->introductionOf($enemy->key));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            ['key' => $this->enemy->key, 'name' => $this->name, 'minimumLevel' => $this->enemy->level],
            FighterResource::from([
                'hp' => $this->enemy->hp,
                'damage' => $this->enemy->damage,
                'mitigationPermille' => $this->enemy->mitigationPermille,
                'comboPermille' => $this->enemy->comboPermille,
                'dodgePermille' => $this->enemy->dodgePermille,
                'maintenancePermille' => $this->enemy->maintenancePermille,
                'criticalChancePermille' => $this->enemy->criticalChancePermille,
                'guardPermille' => $this->enemy->guardPermille,
                'criticalResistancePermille' => $this->enemy->criticalResistancePermille,
                'cooldownReductionPermille' => $this->enemy->cooldownReductionPermille,
                'precisionPermille' => $this->enemy->precisionPermille,
            ])->toArray(),
            ['imageUrls' => $this->imageUrls, 'introduction' => $this->introduction],
        );
    }
}
