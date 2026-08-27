<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Application\ProgressionState;
use App\Progression\Domain\TitleProgress;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\PlayerTitle;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
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
 *
 * **`attributes` (#163) porte les cinq caractéristiques, Vitality comprise, mais sous une
 * forme différente de celle du `RewardSummary` (#162).** Le `RewardSummary` anime un
 * *passage* — chaque caractéristique y porte `gained`, `before` et `after`. Cette route ne
 * rend qu'un instant : il n'y a ni gain ni avant/après à en tirer, seulement la valeur
 * courante. Les cinq clés sont les mêmes (`strength`, `endurance`, `mobility`, `dexterity`,
 * `vitality`) parce qu'un client qui les lirait sous deux orthographes écrirait deux
 * mappings pour le même concept ; c'est la forme, pas le vocabulaire, qui diffère
 * légitimement.
 *
 * **`vitalityBreakdown` (#165) n'a pas d'équivalent dans `RewardSummary`.**
 * `attributes.vitality` porte désormais la Vitality *bonifiée* par l'énergie active de la
 * fenêtre glissante — une valeur qui peut bouger sans qu'aucune séance n'ait été créditée,
 * simplement parce qu'une journée vient de s'ajouter à la fenêtre. Sans `vitalityBreakdown`,
 * ce mouvement serait injustifiable pour le joueur ; avec lui, l'écran peut dire « +8 %
 * parce que tu as bougé 420 kcal en moyenne sur 500 visées ». Il n'a donc de sens qu'à côté
 * d'un état, jamais d'un passage — ce qui explique aussi pourquoi il n'entre pas dans
 * `RewardSummary`, où Vitality n'anime déjà qu'un avant/après sans bonus (#162).
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
        public AttributeGains $attributes,
        public int $vitality,
        public VitalityBreakdown $vitalityBreakdown,
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
            $state->attributes,
            $state->vitality,
            $state->vitalityBreakdown,
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
            // L'état courant des cinq caractéristiques — voir le docblock de la classe pour
            // pourquoi la forme diffère de celle du `RewardSummary` alors que le vocabulaire
            // ne bouge pas.
            'attributes' => [
                'strength' => $this->attributes->strength,
                'endurance' => $this->attributes->endurance,
                'mobility' => $this->attributes->mobility,
                'dexterity' => $this->attributes->dexterity,
                'vitality' => $this->vitality,
            ],
            // Ce qui explique la valeur juste au-dessus — voir le docblock de la classe.
            'vitalityBreakdown' => [
                'windowAverageActiveKcal' => $this->vitalityBreakdown->windowAverageActiveKcal,
                'targetActiveKcal' => $this->vitalityBreakdown->targetActiveKcal,
                'bonusPermille' => $this->vitalityBreakdown->bonusPermille,
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
