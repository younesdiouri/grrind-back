<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use DateTimeImmutable;

/**
 * Un fait accompli, qu'un module publie et que les autres apprennent.
 *
 * **Où ils vivent.** Ici, dans `Shared`, et nulle part ailleurs. Un événement qui
 * franchit une frontière de module *est* le contrat entre les deux : le laisser chez son
 * émetteur obligerait `Progression` à importer une classe de `Training`, ce que Deptrac
 * interdit — et à juste titre, puisque c'est exactement la dépendance qu'on refuse.
 * L'émetteur ne connaît pas ses abonnés, les abonnés ne connaissent pas l'émetteur ;
 * tous connaissent `Shared`.
 *
 * **Comment on les nomme.** Au passé, du point de vue du domaine :
 * `TrainingSessionCompleted`, pas `CompleteTrainingSession` ni `SessionCompletedEvent`.
 * Un événement décrit ce qui *a eu lieu* — on ne le refuse pas, on en prend acte.
 *
 * **Ce qu'ils portent.** Des scalaires, des enums de `Shared`, des `Uuid`. Jamais une
 * entité : le message survit à la transaction qui l'a produit, et il sera désérialisé
 * plus tard, dans un autre processus, où l'entité aurait déjà changé. Le payload est
 * autoportant — l'abonné ne doit avoir aucune raison de rappeler l'émetteur.
 *
 * **Comment on s'y abonne.** Un `#[AsMessageHandler]` dans n'importe quel module, typé
 * sur l'événement. Rien à déclarer ailleurs.
 *
 * **Comment ils sont publiés.** Par le bus, *dans la transaction* qui écrit le fait
 * (pattern outbox — voir `config/packages/messenger.yaml`). Un COMMIT raté ne laisse
 * jamais d'événement derrière lui, et un fait écrit n'est jamais sans son événement.
 */
interface DomainEvent
{
    /**
     * L'instant du fait, sur l'horloge serveur — pas celui de la publication. Les deux
     * coïncident aujourd'hui ; ils divergeront le jour où une activité s'importera après
     * coup, et c'est le fait qui compte.
     */
    public function occurredAt(): DateTimeImmutable;
}
