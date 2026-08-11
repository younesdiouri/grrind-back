<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\TitleProgress;
use DateTimeImmutable;

/**
 * Le mur des titres d'un joueur : le catalogue entier situé pour lui, ce qu'il a déjà et
 * depuis quand, ce qu'il porte, ce qu'il vise.
 *
 * Assemblé d'une seule lecture du ledger et d'une seule de chaque table — c'est ce qui
 * permet à `GET /api/titles` comme à `GET /api/me` de partir du même objet sans que l'un
 * paie pour l'autre.
 */
final readonly class TitleBoard
{
    /**
     * @param list<TitleProgress>              $titles     le catalogue entier, dans l'ordre de déclaration
     * @param array<string, DateTimeImmutable> $unlockedAt identifiant → date de déblocage, pour les seuls acquis
     * @param string|null                      $activeId   le titre affiché, s'il y en a un
     * @param TitleProgress|null               $next       le plus proche d'aboutir parmi ceux qui restent
     */
    public function __construct(
        public array $titles,
        public array $unlockedAt,
        public ?string $activeId,
        public ?TitleProgress $next,
    ) {
    }

    /**
     * Le titre porté, situé. `null` si le joueur n'en affiche aucun — ou si celui qu'il
     * affichait a disparu du catalogue, ce qu'un titre retiré du YAML produit : la ligne
     * survit en base, le mur ne la montre plus.
     */
    public function active(): ?TitleProgress
    {
        foreach ($this->titles as $title) {
            if ($title->title->id === $this->activeId) {
                return $title;
            }
        }

        return null;
    }

    public function unlockedAtOf(string $titleId): ?DateTimeImmutable
    {
        return $this->unlockedAt[$titleId] ?? null;
    }
}
