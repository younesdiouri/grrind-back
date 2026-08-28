<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Shared\Application\PlayerProfile;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le tour en cours : qui doit choisir, jusqu'à quand, et ce qu'il a choisi s'il l'a déjà
 * fait.
 *
 * **Une information pour tout le monde, une action pour un seul.** `mine` évite au client de
 * comparer des UUID pour savoir s'il doit afficher un bouton ou une phrase.
 *
 * **`choosable` est rendue même quand le tour n'est pas le nôtre**, et c'est délibéré : c'est
 * une propriété de l'état de la guilde — les disciplines qui créditent, moins celles déjà
 * défiées — pas un secret de son porteur. Une liste présente ou absente selon l'appelant
 * donnerait deux formes au même type côté client pour ne rien protéger.
 *
 * La discipline choisie, elle, **n'est visible que par son auteur** tant que la révélation
 * n'a pas eu lieu : tout l'intérêt de la mécanique est que le choix soit fait à l'aveugle,
 * et l'annoncer d'avance viderait le rendez-vous du dimanche soir de sa raison d'être.
 */
final readonly class RisalaTurnView
{
    public function __construct(
        public Uuid $senderId,
        public ?PlayerProfile $sender,
        public bool $mine,
        public DateTimeImmutable $deadline,
        public ?Discipline $discipline,
        /** @var list<Discipline> */
        public array $choosable,
    ) {
    }
}
