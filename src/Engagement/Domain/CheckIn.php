<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un jour où le joueur a ouvert l'app (#346) : toute la matière du streak. La clé primaire
 * fait l'idempotence — un second pointage le même jour ne change rien.
 *
 * Écrit par `INSERT … ON CONFLICT DO NOTHING` dans {@see \App\Engagement\Application\StreakTracker} ;
 * l'entité n'existe que pour que le schéma reste décrit par le mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_check_in')]
class CheckIn
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
        #[ORM\Id]
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $day,
    ) {
    }
}
