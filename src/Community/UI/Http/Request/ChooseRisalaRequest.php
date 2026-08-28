<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le sport qu'on envoie à sa guilde.
 *
 * Un `Discipline` typé et non une chaîne : le Serializer refuse tout seul une valeur hors de
 * l'énumération, et le message part en 422 avec les autres violations. Rien à valider à la
 * main, donc rien à oublier de valider.
 *
 * Ce que le DTO ne dit **pas** est aussi le contrat : la discipline doit créditer de l'XP et
 * ne pas être déjà défiée. Ces deux règles ne se lisent pas sur la requête mais sur l'état de
 * la guilde, donc elles vivent dans {@see \App\Community\Domain\Risala::choose()} — les
 * recopier ici en ferait une seconde formulation qui finirait par diverger.
 */
final readonly class ChooseRisalaRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Une Risāla demande une discipline.')]
        public ?Discipline $discipline = null,
    ) {
    }
}
