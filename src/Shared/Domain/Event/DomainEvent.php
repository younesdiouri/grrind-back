<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use DateTimeImmutable;

/**
 * Un fait accompli, qu'un module publie et que les autres apprennent. La convention,
 * pour tout événement ajouté ici :
 *
 * - **il vit dans `Shared`** — il *est* le contrat entre deux modules, et Deptrac
 *   interdit qu'un abonné importe une classe de son émetteur ;
 * - **il se nomme au passé** — `TrainingSessionCompleted`, jamais `…Event` ;
 * - **il ne porte que des scalaires, des enums de `Shared` et des `Uuid`.** Jamais
 *   d'entité : il sera désérialisé plus tard, dans un autre processus, où l'entité
 *   aurait changé. Le payload est autoportant ;
 * - **on s'y abonne par `#[AsMessageHandler]`**, dans n'importe quel module ;
 * - **il est publié dans la transaction qui écrit le fait** — voir
 *   `config/packages/messenger.yaml`.
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
