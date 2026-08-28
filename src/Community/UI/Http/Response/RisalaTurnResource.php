<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\RisalaTurnView;
use App\Shared\Domain\Activity\Discipline;
use DateTimeInterface;

/**
 * Le tour en cours. Une information pour tout le monde, une action pour un seul — d'où
 * `mine`, qui évite au client de comparer des UUID pour savoir s'il dessine un bouton ou une
 * phrase.
 *
 * **`discipline` n'est renseignée que pour son auteur.** Le choix se fait à l'aveugle : c'est
 * toute la mécanique, et l'annoncer d'avance viderait le rendez-vous du dimanche soir de sa
 * raison d'être.
 *
 * **`choosable` est là pour tout le monde**, parce que c'est une propriété de l'état de la
 * guilde — les disciplines qui créditent, moins celles déjà défiées — et pas un secret. Une
 * liste présente ou absente selon l'appelant donnerait deux formes au même type côté client
 * sans rien protéger.
 */
final readonly class RisalaTurnResource
{
    public function __construct(
        public string $senderId,
        public ?string $senderDisplayName,
        public bool $mine,
        public string $deadline,
        public ?string $discipline,
        /** @var list<string> */
        public array $choosable,
    ) {
    }

    public static function from(RisalaTurnView $turn): self
    {
        return new self(
            $turn->senderId->toRfc4122(),
            $turn->sender?->displayName,
            $turn->mine,
            $turn->deadline->format(DateTimeInterface::ATOM),
            $turn->discipline?->value,
            array_map(static fn (Discipline $discipline): string => $discipline->value, $turn->choosable),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'senderId' => $this->senderId,
            'senderDisplayName' => $this->senderDisplayName,
            'mine' => $this->mine,
            'deadline' => $this->deadline,
            'discipline' => $this->discipline,
            'choosable' => $this->choosable,
        ];
    }
}
