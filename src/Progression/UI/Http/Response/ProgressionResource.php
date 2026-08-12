<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Application\ProgressionState;
use App\Progression\Domain\TitleProgress;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\PlayerTitle;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * L'état du joueur tel que le client le reçoit — l'écran d'accueil de l'app en une requête.
 *
 * `xpToNextLevel` à `null` **signifie le niveau maximum**, et c'est pour ça qu'il n'est pas
 * remplacé par zéro : zéro voudrait dire « atteint », donc une barre pleine sur le point de
 * basculer, ce qui n'arrivera jamais.
 *
 * **Les points de compétence sont deux nombres**, alors qu'ils valent la même chose
 * aujourd'hui. `earned` est un cumul de carrière qui ne redescend pas, `available` est un
 * solde que les arbres (#32) feront baisser. Les fondre en un seul champ maintenant
 * obligerait à le renommer — donc à casser le client — le jour où le premier point se
 * dépense.
 *
 * `rulesetVersion` dit sous quel équilibrage cet état a été projeté. Le client n'en fait
 * rien aujourd'hui ; un rapport de bug, si.
 */
final readonly class ProgressionResource
{
    /**
     * @param list<PlayerTitle> $unlockedTitles
     */
    private function __construct(
        public int $level,
        public int $totalXp,
        public int $xpIntoLevel,
        public ?int $xpToNextLevel,
        public int $earnedSkillPoints,
        public int $availableSkillPoints,
        public ?PlayerTitle $activeTitle,
        public array $unlockedTitles,
        public string $rulesetVersion,
        public ?DateTimeImmutable $lastProgressionAt,
    ) {
    }

    public static function from(ProgressionState $state, TitleTranslator $translator): self
    {
        $titles = array_map(
            static fn (TitleProgress $progress): PlayerTitle => $translator->describe(
                $progress,
                $state->unlockedAtOf($progress->title->id),
            ),
            $state->unlockedTitles,
        );

        return new self(
            $state->standing->level,
            $state->totalXp,
            $state->standing->xpIntoLevel,
            $state->standing->xpToNextLevel,
            $state->standing->earnedSkillPoints,
            $state->availableSkillPoints,
            // Cherché parmi les acquis et non lu du champ brut : un titre retiré du
            // catalogue laisse sa ligne en base, et il n'a rien à faire dans une réponse
            // qui ne le liste plus.
            self::activeAmong($titles, $state->activeTitleId),
            $titles,
            $state->rulesetVersion,
            $state->lastProgressionAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'totalXp' => $this->totalXp,
            'xpIntoLevel' => $this->xpIntoLevel,
            'xpToNextLevel' => $this->xpToNextLevel,
            'skillPoints' => [
                'earned' => $this->earnedSkillPoints,
                'available' => $this->availableSkillPoints,
            ],
            // La même forme qu'à `GET /api/me` et `GET /api/titles` : un seul type à
            // décoder et un seul composant à dessiner côté client.
            'activeTitle' => $this->activeTitle?->toArray(),
            'unlockedTitles' => array_map(static fn (PlayerTitle $title): array => $title->toArray(), $this->unlockedTitles),
            'rulesetVersion' => $this->rulesetVersion,
            'lastProgressionAt' => $this->lastProgressionAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<PlayerTitle> $titles
     */
    private static function activeAmong(array $titles, ?string $activeId): ?PlayerTitle
    {
        foreach ($titles as $title) {
            if ($title->id === $activeId) {
                return $title;
            }
        }

        return null;
    }
}
