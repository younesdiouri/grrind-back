<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelStanding;
use App\Progression\Domain\TitleProgress;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
use DateTimeImmutable;

/**
 * L'état d'un joueur à l'ouverture de l'app : où il en est sur la courbe, ce qu'il a
 * débloqué, ce qu'il porte, ce qu'il peut dépenser.
 *
 * **Assemblé du snapshot, jamais du ledger.** C'est la raison d'être du cache : un joueur
 * qui ouvre l'app ne doit pas payer la relecture de son historique. Trois lectures indexées
 * en tout — une par clé primaire, deux par identifiant de compte — et aucune agrégation.
 *
 * Ce n'est pas le mur des titres. {@see TitleBoard} montre le catalogue **entier**, situé
 * sur un relevé du ledger, parce qu'un écran de sélection doit donner à viser ; ici on ne
 * montre que l'acquis, et c'est précisément ce qui permet de se passer du ledger.
 *
 * `attributes` porte les quatre caractéristiques (#160), lues du snapshot comme le reste.
 * `vitality` s'y ajoute (#163) — **sa base est lue du snapshot**, jamais rederivée des
 * quatre caractéristiques ici : `ProgressionSnapshot::project()` l'a déjà fait, une seconde
 * version du calcul divergerait au premier changement de coefficient. `/api/progression` les
 * rend désormais toutes les cinq (#163) ; le #162 les avait servies en premier par le
 * `RewardSummary`, où l'avant/après anime les cinq jauges — cette route-ci ne rend qu'un
 * instant, donc l'état courant seul, sans `gained` ni avant/après.
 *
 * **`vitality` est bonifiée (#165), et c'est la seule valeur de cette classe qui n'est pas
 * qu'une relecture du snapshot.** `ProgressionStateProvider` la fait passer par
 * `VitalityBonusProvider` avant de construire cet objet — voir son docblock pour pourquoi
 * ça reste hors du snapshot plutôt qu'une nouvelle colonne. `vitalityBreakdown` porte de
 * quoi l'expliquer : sans lui, une valeur qui bouge sans qu'aucune séance n'y soit pour rien
 * ne récompenserait rien aux yeux du joueur.
 */
final readonly class ProgressionState
{
    /**
     * @param LevelStanding                    $standing          projeté par la courbe, relu tel quel du snapshot
     * @param AttributeGains                   $attributes        les quatre caractéristiques, relues telles quelles du snapshot
     * @param int                              $vitality          la cinquième, bonifiée par l'énergie active de la fenêtre — voir le docblock de la classe
     * @param VitalityBreakdown                $vitalityBreakdown ce qui explique le bonus ci-dessus
     * @param list<TitleProgress>              $unlockedTitles    les seuls acquis, dans l'ordre du catalogue
     * @param array<string, DateTimeImmutable> $unlockedAt        identifiant de titre → date de déblocage
     * @param string|null                      $activeTitleId     le titre affiché, s'il y en a un
     * @param DateTimeImmutable|null           $lastProgressionAt `null` pour un joueur qui n'a encore rien fait
     */
    public function __construct(
        public int $totalXp,
        public LevelStanding $standing,
        public AttributeGains $attributes,
        public int $vitality,
        public VitalityBreakdown $vitalityBreakdown,
        public int $availableSkillPoints,
        public array $unlockedTitles,
        public array $unlockedAt,
        public ?string $activeTitleId,
        public string $rulesetVersion,
        public ?DateTimeImmutable $lastProgressionAt,
    ) {
    }

    public function unlockedAtOf(string $titleId): ?DateTimeImmutable
    {
        return $this->unlockedAt[$titleId] ?? null;
    }
}
