<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que la séance a rapporté — le fait symétrique de {@see WorkoutImported}, et publié par
 * `Progression`, pas par `Training` : c'est le seul module qui sait ce qu'un workout a
 * gagné, `Training` ne fait que déclencher le calcul par le port
 * {@see \App\Shared\Application\SessionRewards}.
 *
 * **`WorkoutImported` ne pouvait pas porter ces champs.** Il est l'argument de
 * `SessionRewards::creditFor()` : il existe donc *avant* que l'XP soit calculée, et l'y
 * ajouter aurait demandé de le construire deux fois, ou de muter un `readonly` qui est
 * juste. `SessionRewards` ne convenait pas non plus — son docblock l'interdit hors de la
 * transaction d'import, quand un abonné asynchrone en est par définition dehors.
 *
 * **Charge minimale et assumée**, comme pour `WorkoutImported` : `xpGranted` et
 * `rulesetVersion` disent le montant et sous quel équilibrage il a été accordé,
 * `levelBefore`/`levelAfter` disent si la séance a fait franchir un niveau. La largeur du
 * palier (`xpIntoLevel`, `xpToNextLevel`), le détail du calcul et les titres débloqués
 * restent dans `SessionReward` : c'est ce qui anime la barre de la réponse HTTP, pas ce
 * qu'un abonné asynchrone a un usage décidé pour l'instant. La discipline et la durée ne
 * voyagent pas non plus : un abonné qui en a besoin les retrouve par `workoutId`, sur
 * `WorkoutImported`.
 *
 * **Un par workout crédité, jamais un agrégat par synchronisation** — même raison que
 * `WorkoutImported` : le classement compte des activités, pas des synchronisations.
 *
 * **Absent si le workout n'a pas été crédité.** Un workout hors fenêtre d'antériorité est
 * conservé sans être crédité ; il n'y a alors pas de fait à annoncer, et cette classe n'est
 * simplement pas instanciée — `SessionRewards::creditFor()`, seul point de départ de cet
 * événement, n'est pas appelé pour lui.
 */
final readonly class WorkoutCredited implements DomainEvent
{
    public function __construct(
        public Uuid $workoutId,
        public Uuid $userId,
        public int $xpGranted,
        public string $rulesetVersion,
        /** Le palier d'où le joueur partait avant cette séance. */
        public int $levelBefore,
        /** Le palier où il se trouve après — égal au précédent si aucun niveau n'a été franchi. */
        public int $levelAfter,
        /**
         * L'instant du **sport**, le même que celui que `WorkoutImported` porte pour ce
         * workout — pas celui du crédit ni de la publication. Les deux faits décrivent la
         * même séance, ils partagent sa date ; c'est pour ça qu'il n'a pas sa place dans la
         * charge utile documentée ci-dessus, il n'ajoute rien que `workoutId` ne retrouve
         * déjà sur l'autre événement.
         */
        private DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
