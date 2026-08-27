<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Reward;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\LevelStanding;
use App\Progression\Domain\Title;
use App\Progression\Domain\TitleProgress;
use App\Progression\Domain\XpAwardReason;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpRates;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\PlayerTitle;
use App\Shared\Application\SessionReward;
use App\Shared\Application\SessionRewards;
use App\Shared\Application\XpLine;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Event\WorkoutCredited;
use App\Shared\Domain\Event\WorkoutImported;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * L'implémentation du port {@see SessionRewards} : c'est par cette classe, et uniquement
 * par elle, que `Training` crédite un joueur.
 *
 * Elle ne décide de rien. Toute la séquence — verrou, charge du jour, modificateurs,
 * calcul, ledger, snapshot, titres — est celle de {@see GrantXpHandler}, écrite au Lot 3 et
 * inchangée ici. Cette classe traduit : ce qui entre est un fait de `Training`, ce qui sort
 * est du vocabulaire de `Shared`.
 *
 * **Le verrou est posé par le handler, pas ici**, et il l'est *à l'intérieur* de la
 * transaction ouverte par `Training`. Le `wrapInTransaction` imbriqué ne rouvre rien : DBAL
 * en fait un point de sauvegarde, le verrou de ligne court jusqu'au COMMIT extérieur, et
 * une exception levée après le crédit défait bien le crédit.
 *
 * **C'est aussi elle qui publie {@see WorkoutCredited}** (#128) : `Progression` est le seul
 * module qui sait ce qu'une séance a rapporté, et cette méthode est le seul endroit où ce
 * fait existe. La publication a lieu ici, sous le même point de sauvegarde que le crédit,
 * donc dans la transaction que `Training` tient ouverte — un rollback en aval défait le
 * crédit et l'annonce ensemble. Elle n'a lieu que si `creditFor` est appelée : un workout
 * hors fenêtre n'atteint jamais cette classe, donc rien ne se publie pour lui.
 *
 * **Une discipline qui ne crédite pas s'arrête avant tout ça (#167).** `credits()` est la
 * première question posée, avant `GrantXpHandler` : pour `WALKING`, aucun verrou, aucune
 * lecture de la charge du jour, aucune `XpTransaction`, aucun `WorkoutCredited`. C'est ce
 * qui garantit qu'une marche ne consomme ni les rendements décroissants ni le plafond
 * quotidien d'une autre discipline pratiquée le même jour — la contaminer y punirait la
 * vraie séance, ce qui serait pire que neutre. {@see uncredited()} rend malgré tout un
 * `SessionReward` complet : la séance est créditée pour `Training` au sens où elle entre
 * dans `imported`, pas dans `skipped` — elle est écrite, visible, animée — simplement à
 * zéro XP et avec une raison.
 */
final readonly class LedgerSessionRewards implements SessionRewards
{
    public function __construct(
        private GrantXpHandler $grantXp,
        private TitleTranslator $titles,
        private XpRates $rates,
        // Lu **sans verrou** : `uncredited()` ne modifie rien, donc rien à sérialiser
        // contre une complétion concurrente. Une lecture qui verrouille pour ne rien
        // écrire prendrait un verrou pour rien, exactement ce que `ProgressionStateProvider`
        // refuse déjà pour la même raison.
        private ProgressionSnapshotRepository $snapshots,
        private LevelCurve $curve,
        private string $rulesetVersion,
        // `event.bus` explicitement (#155) : `WorkoutCredited` est un `DomainEvent`, voir
        // le docblock de `messenger.yaml` pour pourquoi ce bus-là tolère l'absence
        // d'abonné et pourquoi `#[Target]` n'est pas optionnel ici.
        #[Target('event.bus')]
        private MessageBusInterface $events,
    ) {
    }

    public function creditFor(WorkoutImported $workout): SessionReward
    {
        if (!$this->rates->credits($workout->discipline)) {
            return $this->uncredited($workout);
        }

        $granted = ($this->grantXp)(new GrantXp(
            $workout->userId,
            $workout->workoutId,
            $workout->discipline,
            $workout->durationSeconds,
            // L'instant du sport, que l'événement porte déjà. C'est lui qui range l'écriture
            // dans une journée — un workout de mardi importé vendredi compte pour mardi.
            $workout->occurredAt(),
            $workout->distanceMeters,
            $workout->elevationGainMeters,
        ));

        $snapshot = $granted->snapshot;
        $before = $granted->standingBefore;

        // Publié ici et non par `Training` : lui ne sait pas ce que la séance a rapporté,
        // seulement qu'elle a été soumise au crédit. Toujours dans la transaction ouverte
        // par l'appelant — le transport Doctrine partage sa connexion, donc son COMMIT.
        //
        // `discipline`, `endedAt` et `durationSeconds` sont repris tels quels de `$workout`
        // — la durée y est déjà la valeur retenue, écrêtée au plafond — et non recalculés :
        // les deux événements décrivent le même `Workout`, aucun des deux ne doit dériver
        // l'autre (voir le docblock de `WorkoutCredited`).
        $this->events->dispatch(new WorkoutCredited(
            $workout->workoutId,
            $workout->userId,
            $workout->discipline,
            $workout->endedAt,
            $workout->durationSeconds,
            $granted->award->amount(),
            $granted->award->rulesetVersion,
            $before->level,
            $snapshot->level(),
            $workout->occurredAt(),
        ));

        return new SessionReward(
            $granted->award->amount(),
            array_map(
                static fn (XpBreakdownLine $line): XpLine => new XpLine($line->source->value, $line->amount),
                $granted->award->breakdown->lines,
            ),
            // Le palier d'où le joueur part, dans son entier : le client y place la barre
            // avant de la remplir, et sa largeur ne se redéduit pas du reste du payload
            // quand plusieurs niveaux sont franchis (#79). Le lire sur le snapshot serait
            // le lire *après* la reprojection, donc lire l'arrivée — d'où `standingBefore`,
            // capturé par le handler du bon côté du `retotal`.
            $before->level,
            $before->xpIntoLevel,
            $before->xpToNextLevel,
            $snapshot->level(),
            $snapshot->totalXp(),
            $snapshot->xpIntoLevel(),
            $snapshot->xpToNextLevel(),
            $granted->levelsReached,
            $granted->skillPointsGranted,
            array_map(
                // Barre pleine et date de la transaction : un titre qui vient d'être
                // débloqué l'est à l'instant même que `TitleUnlocker` a écrit en base —
                // celui que le snapshot porte, pas une seconde lecture de l'horloge.
                fn (Title $title): PlayerTitle => $this->titles->describe(
                    TitleProgress::completed($title),
                    $snapshot->updatedAt(),
                ),
                $granted->titlesUnlocked,
            ),
            // Le détail de cette séance, déjà porté par `XpAward` (#159) — aucun nouveau
            // calcul, juste le même vecteur réexporté vers `Shared`.
            $granted->award->attributeGains,
            // Même geste qu'au palier de niveau : `attributesBefore` vient du handler, lu
            // avant `retotal()` ; l'arrivée se relit directement sur le snapshot reprojeté.
            $granted->attributesBefore,
            $snapshot->attributes(),
            // Vitality peut bouger sans que cette séance lui ait rien crédité — c'est tout
            // l'intérêt de la lire avant/après plutôt que de la dériver du gain (#162).
            $granted->vitalityBefore,
            $snapshot->vitality(),
            $granted->award->rulesetVersion,
        );
    }

    /**
     * Le `SessionReward` d'une discipline qui ne crédite pas d'XP : zéro partout, une
     * raison, et un avant/après identique puisque rien n'a bougé.
     *
     * **Aucune écriture.** Le snapshot est lu, jamais verrouillé ni créé : un joueur qui
     * n'a jamais rien fait n'a pas de ligne, et une marche ne doit pas lui en poser une —
     * même raison que {@see \App\Progression\Application\ProgressionStateProvider}, qui
     * refuse la même écriture pour la même raison. `LevelStanding` et les caractéristiques
     * neutres reprennent donc le geste de cette classe-là plutôt que d'en inventer un.
     */
    private function uncredited(WorkoutImported $workout): SessionReward
    {
        $snapshot = $this->snapshots->ofPlayer($workout->userId);

        $standing = null !== $snapshot
            ? new LevelStanding($snapshot->level(), $snapshot->xpIntoLevel(), $snapshot->xpToNextLevel(), $snapshot->earnedSkillPoints())
            : $this->curve->standingAt(0);

        $attributes = $snapshot?->attributes() ?? new AttributeGains(0, 0, 0, 0);
        $vitality = $snapshot?->vitality() ?? 0;

        return new SessionReward(
            xpAwarded: 0,
            // Vide, et non une ligne « base : 0 » qui prétendrait avoir calculé quelque
            // chose : `reason`, plus bas, porte l'explication à sa place.
            breakdown: [],
            levelBefore: $standing->level,
            xpIntoLevelBefore: $standing->xpIntoLevel,
            xpToNextLevelBefore: $standing->xpToNextLevel,
            level: $standing->level,
            totalXp: $snapshot?->totalXp() ?? 0,
            xpIntoLevel: $standing->xpIntoLevel,
            xpToNextLevel: $standing->xpToNextLevel,
            levelsReached: [],
            skillPointsGranted: 0,
            titlesUnlocked: [],
            attributeGains: new AttributeGains(0, 0, 0, 0),
            attributesBefore: $attributes,
            attributesAfter: $attributes,
            vitalityBefore: $vitality,
            vitalityAfter: $vitality,
            rulesetVersion: $this->rulesetVersion,
            reason: XpAwardReason::NoXpFeedsVitality->value,
        );
    }
}
