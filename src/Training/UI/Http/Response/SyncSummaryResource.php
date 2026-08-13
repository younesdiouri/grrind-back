<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Application\SkippedWorkout;
use App\Training\Application\WorkoutImport;
use DateTimeInterface;

/**
 * Ce que le joueur reçoit d'une synchronisation — le moment dopamine, répété N fois.
 *
 * ————— L'invariant ne change pas, il gagne une dimension ————————————————————————————————
 *
 * **L'ordre des champs est l'ordre de l'animation**, maintenant à deux niveaux : d'abord
 * entre les workouts, dans l'ordre chronologique ; puis à l'intérieur de chacun, dans celui
 * que {@see RewardSummaryResource} fixe depuis le Lot 4. Un champ déplacé change toujours la
 * mise en scène.
 *
 * ————— Pourquoi le `RewardSummary` par workout ne change pas de forme —————————————————————
 *
 * C'est ce qui rend ce ticket peu coûteux côté client : chaque élément d'`imported` est
 * exactement l'objet qu'il sait déjà jouer, palier de départ compris. La barre part du bon
 * endroit pour *chacun*, et l'enchaînement de dix workouts est continu **sans un seul
 * recalcul côté client**. C'est précisément pour ça que `SessionReward` porte
 * `levelBefore`, `xpIntoLevelBefore` et `xpToNextLevelBefore` depuis le #79 ; cette
 * décision-là vient de payer.
 *
 * ————— `totals` est un raccourci, jamais une source ——————————————————————————————————————
 *
 * Il sert à l'écran de résumé — « +847 XP · niveau 10 → 15 » — et au joueur qui saute
 * l'animation. Il ne doit jamais être la seule façon de connaître l'état final : `imported`
 * le dit déjà, et deux vérités finissent toujours par diverger. Il est donc **entièrement
 * dérivé** de la liste, ici, sans qu'aucune requête aille le chercher.
 *
 * Conséquence assumée : il est **`null` quand rien n'a été crédité**. Il n'y a pas d'état
 * d'arrivée quand rien n'est arrivé, et écrire « niveau 0 → 0 » mentirait à un joueur de
 * niveau 12. Le client a de toute façon ce test à faire — il n'affiche pas un écran de
 * résumé pour une synchronisation vide.
 *
 * ————— Rien n'est tronqué ————————————————————————————————————————————————————————————————
 *
 * Au-delà d'une vingtaine de workouts, jouer chaque animation prend plusieurs minutes. C'est
 * une décision de mise en scène, et elle se tranche côté client
 * (younesdiouri/grrind-app#18) : **le serveur envoie tout, le client décide de ce qu'il
 * joue.** Tronquer ici lui retirerait le choix, et personne ne saurait plus ce que l'import
 * a réellement crédité.
 */
final readonly class SyncSummaryResource
{
    /**
     * @param list<RewardSummaryResource> $imported
     * @param list<SkippedWorkout>        $skipped
     */
    private function __construct(
        public string $syncedAt,
        public array $imported,
        public array $skipped,
        public ?SyncTotals $totals,
        public string $rulesetVersion,
    ) {
    }

    public static function from(WorkoutImport $import, string $rulesetVersion): self
    {
        return new self(
            $import->syncedAt->format(DateTimeInterface::ATOM),
            array_map(RewardSummaryResource::from(...), $import->imported),
            $import->skipped,
            SyncTotals::of($import->imported),
            $rulesetVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // L'horloge du serveur, et le seul instant de ce payload qui n'en vienne pas
            // d'un fournisseur. Le client s'en sert comme repère de sa dernière
            // synchronisation réussie.
            'syncedAt' => $this->syncedAt,
            'imported' => array_map(
                static fn (RewardSummaryResource $reward): array => $reward->toArray(),
                $this->imported,
            ),
            // Un workout qui disparaît sans un mot est un bug du point de vue du joueur,
            // même quand le serveur a raison de l'écarter. Chacun est nommé, avec son type
            // d'activité brut : c'est ce qui permet d'écrire « le curling n'est pas encore
            // un sport chez nous » au lieu de « 1 séance ignorée ».
            'skipped' => array_map(
                static fn (SkippedWorkout $skipped): array => [
                    'externalId' => $skipped->externalId,
                    'activityType' => $skipped->activityType,
                    'reason' => $skipped->reason->value,
                ],
                $this->skipped,
            ),
            'totals' => $this->totals?->toArray(),
            // Sous quel équilibrage ces montants ont été accordés. Le client l'affiche dans
            // un rapport de bug ; c'est ce qui rend une capture d'écran exploitable.
            'rulesetVersion' => $this->rulesetVersion,
        ];
    }
}
