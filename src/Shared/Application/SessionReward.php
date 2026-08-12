<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Ce qu'une séance a rapporté, tel que `Training` le reçoit de `Progression` pour en faire
 * le `RewardSummary` (#22).
 *
 * **Tout est déjà décidé quand cet objet existe** : les montants sont écrits au ledger, le
 * snapshot est reprojeté, les titres sont enregistrés. C'est un compte rendu, pas une
 * intention — personne ne recalcule rien à partir de lui.
 *
 * Il ne porte que des scalaires et des types de `Shared`, pour la même raison qu'un
 * événement de domaine : `Training` ne doit apprendre ni ce qu'est un `XpAward`, ni ce
 * qu'est un `ProgressionSnapshot`. `titlesUnlocked` est en {@see PlayerTitle} — la seule
 * forme JSON d'un titre dans toute l'API — donc un titre gagné en fin de séance se dessine
 * avec le composant qui sert déjà `GET /api/me` et `GET /api/titles`.
 *
 * **Le palier de départ est donné en entier** — `levelBefore`, `xpIntoLevelBefore` et
 * `xpToNextLevelBefore` — bien qu'il se déduise en partie du reste. Le client anime un
 * *passage* : il place la barre, puis la remplit. Sans la largeur du palier d'où elle part,
 * il ne sait pas où la placer, et un franchissement multiple la fait démarrer à zéro pour
 * un joueur qui n'y était pas (#79). Lui faire reconstituer tout ça à partir d'une liste
 * éventuellement vide est exactement le genre de calcul qu'on refait de travers.
 */
final readonly class SessionReward
{
    /**
     * @param list<XpLine>      $breakdown      dans l'ordre d'affichage : le client ne le trie pas, il le joue
     * @param list<int>         $levelsReached  vide si aucun niveau n'a été franchi ; plusieurs est un cas normal
     * @param list<PlayerTitle> $titlesUnlocked vide le plus souvent : un titre est un événement rare, c'est ce qui en fait un
     */
    public function __construct(
        public int $xpAwarded,
        public array $breakdown,
        public int $levelBefore,
        /** Où en était la barre au moment d'appuyer sur « terminer ». */
        public int $xpIntoLevelBefore,
        /** Avec le précédent, la largeur du palier de départ. `null` au niveau maximum. */
        public ?int $xpToNextLevelBefore,
        public int $level,
        public int $totalXp,
        public int $xpIntoLevel,
        /** `null` au niveau maximum — zéro voudrait dire « atteint », donc une barre pleine. */
        public ?int $xpToNextLevel,
        public array $levelsReached,
        /** Les points de compétence que ces niveaux ont accordés. */
        public int $skillPointsGranted,
        public array $titlesUnlocked,
        /** L'équilibrage sous lequel ce montant a été accordé, tel qu'il est écrit au ledger. */
        public string $rulesetVersion,
    ) {
    }
}
