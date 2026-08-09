<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le compte d'un joueur. Volontairement pauvre : tout ce qui relève du jeu (XP,
 * niveau, streak, inventaire) appartient aux autres modules et n'a rien à faire ici.
 * Un User ne connaît ni son niveau ni ses sessions.
 *
 * Aucune colonne `roles` en v1 : tous les comptes sont des joueurs. Le jour où un
 * back-office existera, la colonne arrivera avec sa migration — pas avant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_user')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_email', columns: ['email'])]
class User
{
    public const int DISPLAY_NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    // Les types « email » et « timezone » sont écrits en toutes lettres plutôt
    // qu'importés depuis Infrastructure : le domaine n'a pas à connaître la classe
    // qui le persiste. Ces noms sont enregistrés dans config/packages/doctrine.yaml
    // et font partie du schéma — ils ne bougent plus.
    #[ORM\Column(type: 'email', length: Email::MAX_LENGTH)]
    private Email $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: self::DISPLAY_NAME_MAX_LENGTH)]
    private string $displayName;

    #[ORM\Column(type: 'timezone', length: 64)]
    private Timezone $timezone;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $registeredAt;

    private function __construct(
        Uuid $id,
        Email $email,
        string $passwordHash,
        string $displayName,
        Timezone $timezone,
        DateTimeImmutable $registeredAt,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->displayName = self::validDisplayName($displayName);
        $this->timezone = $timezone;
        $this->registeredAt = $registeredAt;
    }

    /**
     * L'UUID v7 est généré ici, applicativement : il est triable par date de création,
     * ce qui évite une colonne d'ordre sur les gros volumes à venir.
     *
     * `$now` est fourni par l'appelant parce que le serveur possède l'horloge — aucune
     * classe du domaine ne lit l'heure toute seule, sinon plus rien n'est testable.
     */
    public static function register(
        Email $email,
        string $passwordHash,
        string $displayName,
        Timezone $timezone,
        DateTimeImmutable $now,
    ): self {
        return new self(Uuid::v7(), $email, $passwordHash, $displayName, $timezone, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function timezone(): Timezone
    {
        return $this->timezone;
    }

    public function registeredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function rename(string $displayName): void
    {
        $this->displayName = self::validDisplayName($displayName);
    }

    public function moveTo(Timezone $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function changePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    private static function validDisplayName(string $displayName): string
    {
        $trimmed = trim($displayName);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Le pseudo ne peut pas être vide.');
        }

        if (self::DISPLAY_NAME_MAX_LENGTH < mb_strlen($trimmed)) {
            throw new InvalidArgumentException(\sprintf('Pseudo trop long (%d caractères max).', self::DISPLAY_NAME_MAX_LENGTH));
        }

        return $trimmed;
    }
}
