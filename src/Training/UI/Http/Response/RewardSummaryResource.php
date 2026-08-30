<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Shared\Application\DroppedItem;
use App\Shared\Application\PlayerTitle;
use App\Shared\Application\SessionDrop;
use App\Shared\Application\SessionReward;
use App\Shared\Application\XpLine;
use App\Training\Application\SessionCompletion;

/**
 * Ce que le joueur reçoit pour un workout crédité — le payload du moment dopamine.
 *
 * **Il n'a plus de producteur depuis le retrait du chronomètre (#85), et il est gardé
 * exprès.** Le `SyncSummary` du #92 est une liste de ces objets-ci, un par workout
 * importé : le supprimer pour le réécrire « de la forme déjà connue » deux tickets plus
 * loin serait rejouer de mémoire la seule chose du produit qui ne se rattrape pas.
 * `RewardSummaryPayloadTest` fige l'ordre des clés en attendant.
 *
 * **L'ordre des champs est l'ordre de l'animation.** Le client le joue de haut en bas : la
 * séance se referme, la barre d'XP se remplit ligne à ligne, **les cinq jauges de
 * caractéristiques montent**, le niveau bascule, le titre tombe, le loot se révèle, les
 * pièces tombent dedans, puis la série. Ce n'est pas une convention d'écriture, c'est le
 * contrat : un champ déplacé change la mise en scène, et c'est le schéma le plus coûteux à
 * casser du produit.
 *
 * **`attributes` (#162) se place entre `xp` et `level`, et nulle part ailleurs.** Les
 * caractéristiques sont la conséquence directe de l'XP qui vient de tomber — les faire
 * monter après le niveau les couperait de leur cause, et le joueur verrait des jauges
 * bouger sans savoir pourquoi. Le niveau, lui, est la conséquence du **total**, donc il
 * vient après la répartition qui l'alimente : `xp` → `attributes` → `level`, dans cet
 * ordre et pas un autre.
 *
 * **Un seul aller-retour.** Rien ici ne demande au client de recharger quoi que ce soit
 * avant de jouer l'animation — c'est pourquoi le palier est donné avant *et* après, et
 * pourquoi les titres arrivent déjà traduits, dans la forme unique de {@see PlayerTitle}
 * que servent déjà `GET /api/me` et `GET /api/titles`. `attributes` suit la même règle :
 * chacune des cinq caractéristiques porte son avant et son après, Vitality comprise —
 * une jauge qui repartirait de zéro mentirait à tout joueur qui n'y était pas, exactement
 * comme le palier de niveau (#79). **Vitality n'a pas de `gained`** : elle ne reçoit
 * jamais d'XP directement, elle se lit avant/après sur l'état du joueur — voir le
 * docblock d'`App\Shared\Domain\Activity\Vitality` pour pourquoi elle peut bouger sans que
 * cette séance lui ait rien crédité.
 *
 * **`loot` se remplit depuis le #226, `coins` l'accompagne ; `streak` et `unlockableNodes`
 * restent présents et vides.** La série arrive au Lot 5, les arbres au Lot 7 : les ajouter
 * plus tard obligerait le client déjà déployé à traiter des champs qui apparaissent, donc à
 * les rendre optionnels pour toujours ; les déclarer maintenant coûte deux clés et fige la
 * forme.
 *
 * **`coins` se place entre `loot` et `streak`, jamais ailleurs.** Même geste que les jauges
 * de caractéristiques et le palier de niveau — `{gained, before, after}` — et la même
 * raison : une bourse qui repartirait du solde final ne s'anime pas. L'ordre dit la mise en
 * scène : le loot se révèle, **puis** les pièces tombent dedans. `before`/`after` viennent
 * du solde réel lu par `Rewards` ({@see SessionDrop} pour le détail), jamais recomposés
 * depuis `gained` — sur un import de dix workouts, la bourse s'anime dix fois d'affilée, et
 * une reconstruction diverge du vrai solde au premier écart sans que personne ne le voie.
 *
 * **Une séance sans tirage porte `loot: []` et `coins` à gain nul, jamais des clés
 * absentes** — voir {@see SessionDrop::none()} : le client anime la même séquence dans
 * tous les cas, qu'un objet soit tombé ou non.
 *
 * **`loot` porte de quoi afficher chaque objet sans requête supplémentaire** — clé, nom
 * déjà traduit, rareté, emplacement, modificateurs, prix — même geste qu'un seul
 * aller-retour pour {@see PlayerTitle}. Voir le docblock de {@see DroppedItem} pour
 * pourquoi ce n'est ni l'`Item` du catalogue ni une entité `Rewards`.
 *
 * **`xp.reason` (#167) explique un zéro qui n'est pas une punition.** Une marche est bien
 * *ici* — dans `imported`, pas dans `skipped` : elle est écrite, visible, animée — mais ne
 * rapporte aucune XP par conception. `null` pour toute séance normale ; sinon une valeur de
 * `App\Progression\Domain\XpAwardReason` que le client rend en une phrase, à la place d'un
 * `breakdown` vide qui ne dirait rien et d'une ligne « base : 0 » qui mentirait sur un
 * calcul qui n'a jamais eu lieu.
 */
final readonly class RewardSummaryResource
{
    private function __construct(
        public WorkoutResource $session,
        public SessionReward $reward,
        public SessionDrop $drop,
    ) {
    }

    public static function from(SessionCompletion $completion): self
    {
        return new self(
            WorkoutResource::from($completion->session),
            $completion->reward,
            $completion->drop,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session' => $this->session->toArray(),
            'xp' => [
                'awarded' => $this->reward->xpAwarded,
                // Le détail, dans l'ordre où il a été calculé : « 90 de base, −40 parce
                // que tu as déjà beaucoup couru aujourd'hui, +10 grâce à ta série ».
                'breakdown' => array_map(
                    static fn (XpLine $line): array => ['source' => $line->source, 'amount' => $line->amount],
                    $this->reward->breakdown,
                ),
                // `null` sauf pour une discipline qui ne crédite pas d'XP par conception
                // (#167) : `breakdown` reste vide dans ce cas, puisqu'il n'y a rien eu à
                // calculer, et `reason` porte l'explication à sa place — jamais les deux
                // vides en même temps sans un mot pour le dire.
                'reason' => $this->reward->reason,
            ],
            // Entre `xp` et `level`, jamais ailleurs — voir le docblock de la classe.
            'attributes' => [
                'strength' => self::gauge(
                    $this->reward->attributeGains->strength,
                    $this->reward->attributesBefore->strength,
                    $this->reward->attributesAfter->strength,
                ),
                'endurance' => self::gauge(
                    $this->reward->attributeGains->endurance,
                    $this->reward->attributesBefore->endurance,
                    $this->reward->attributesAfter->endurance,
                ),
                'mobility' => self::gauge(
                    $this->reward->attributeGains->mobility,
                    $this->reward->attributesBefore->mobility,
                    $this->reward->attributesAfter->mobility,
                ),
                'dexterity' => self::gauge(
                    $this->reward->attributeGains->dexterity,
                    $this->reward->attributesBefore->dexterity,
                    $this->reward->attributesAfter->dexterity,
                ),
                // Pas de `gained` : Vitality ne reçoit jamais d'XP directement, elle se lit
                // avant/après sur l'état du joueur — voir le docblock de la classe.
                'vitality' => [
                    'before' => $this->reward->vitalityBefore,
                    'after' => $this->reward->vitalityAfter,
                ],
            ],
            'level' => [
                // Le palier de départ vient avant la bascule parce que l'animation est
                // dans cet ordre : la barre se pose là où le joueur en était, puis elle
                // monte. Sa largeur — `xpIntoLevelBefore` + `xpToNextLevelBefore` — est
                // introuvable autrement dès que plusieurs niveaux sont franchis, et une
                // barre qui repart de zéro ment à tout joueur qui n'y était pas.
                'before' => $this->reward->levelBefore,
                'xpIntoLevelBefore' => $this->reward->xpIntoLevelBefore,
                'xpToNextLevelBefore' => $this->reward->xpToNextLevelBefore,
                'after' => $this->reward->level,
                // Plusieurs d'un coup est un cas normal, pas l'exception : le client les
                // anime tous, l'un après l'autre. Vide quand rien n'a été franchi.
                'reached' => $this->reward->levelsReached,
                'totalXp' => $this->reward->totalXp,
                'xpIntoLevel' => $this->reward->xpIntoLevel,
                // `null` signifie le niveau maximum ; zéro voudrait dire « atteint ».
                'xpToNextLevel' => $this->reward->xpToNextLevel,
                'skillPointsGranted' => $this->reward->skillPointsGranted,
            ],
            'titlesUnlocked' => array_map(
                static fn (PlayerTitle $title): array => $title->toArray(),
                $this->reward->titlesUnlocked,
            ),
            'loot' => array_map(
                static fn (DroppedItem $item): array => $item->toArray(),
                $this->drop->items,
            ),
            // Entre `loot` et `streak`, jamais ailleurs — voir le docblock de la classe.
            'coins' => self::gauge($this->drop->coinsGained, $this->drop->coinsBefore, $this->drop->coinsAfter),
            'streak' => null,
            'unlockableNodes' => [],
            // Sous quel équilibrage ces montants ont été accordés. Le client l'affiche dans
            // un rapport de bug ; c'est ce qui rend une capture d'écran exploitable.
            'rulesetVersion' => $this->reward->rulesetVersion,
        ];
    }

    /**
     * Une jauge de caractéristique : ce que la séance lui a rapporté, et son avant/après —
     * même geste qu'au palier de niveau, à l'échelle d'une seule caractéristique.
     *
     * @return array{gained: int, before: int, after: int}
     */
    private static function gauge(int $gained, int $before, int $after): array
    {
        return ['gained' => $gained, 'before' => $before, 'after' => $after];
    }
}
