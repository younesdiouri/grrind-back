<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\WorkoutCredited;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tombeau de compatibilité du producteur #252. L'app iOS programme désormais la notification
 * locale et possède seule le cas « séance créditée en arrière-plan » ; écouter encore
 * {@see WorkoutCredited} ici évite de changer la forme de l'événement pendant ce rollout,
 * mais ne doit créer ni fenêtre ni message. Le contrat historisé est retiré au #256 après
 * drainage des files Messenger, conformément au versioning Messenger.
 */
final class SessionCreditedNotifier
{
    #[AsMessageHandler]
    public function __invoke(WorkoutCredited $_event): void
    {
    }
}
