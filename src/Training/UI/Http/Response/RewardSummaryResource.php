<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Shared\Application\PlayerTitle;
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
 * séance se referme, la barre d'XP se remplit ligne à ligne, le niveau bascule, le titre
 * tombe, puis le loot et la série. Ce n'est pas une convention d'écriture, c'est le contrat :
 * un champ déplacé change la mise en scène, et c'est le schéma le plus coûteux à casser du
 * produit.
 *
 * **Un seul aller-retour.** Rien ici ne demande au client de recharger quoi que ce soit
 * avant de jouer l'animation — c'est pourquoi le palier est donné avant *et* après, et
 * pourquoi les titres arrivent déjà traduits, dans la forme unique de {@see PlayerTitle}
 * que servent déjà `GET /api/me` et `GET /api/titles`.
 *
 * **`loot`, `streak` et `unlockableNodes` sont présents et vides.** Le loot arrive au Lot 6,
 * la série au Lot 5, les arbres au Lot 7. Les ajouter plus tard obligerait le client déjà
 * déployé à traiter des champs qui apparaissent, donc à les rendre optionnels pour toujours ;
 * les déclarer maintenant coûte trois clés et fige la forme.
 */
final readonly class RewardSummaryResource
{
    private function __construct(
        public TrainingSessionResource $session,
        public SessionReward $reward,
    ) {
    }

    public static function from(SessionCompletion $completion): self
    {
        return new self(
            TrainingSessionResource::from($completion->session),
            $completion->reward,
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
            'loot' => [],
            'streak' => null,
            'unlockableNodes' => [],
            // Sous quel équilibrage ces montants ont été accordés. Le client l'affiche dans
            // un rapport de bug ; c'est ce qui rend une capture d'écran exploitable.
            'rulesetVersion' => $this->reward->rulesetVersion,
        ];
    }
}
