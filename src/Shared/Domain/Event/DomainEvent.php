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
 * - **il se nomme au passé** — `WorkoutImported`, jamais `…Event` ;
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
     * L'instant du fait — ni celui de la publication, ni celui de l'écriture.
     *
     * Les deux ne coïncident plus depuis l'import santé : un workout de mardi publié
     * vendredi est daté de mardi. C'était annoncé ici comme une divergence à venir ; elle
     * est arrivée, et c'est bien le fait qui compte.
     */
    public function occurredAt(): DateTimeImmutable;
}
