<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le compte d'un joueur. Volontairement pauvre : tout ce qui relève du jeu (XP,
 * niveau, streak, inventaire) appartient aux autres modules et n'a rien à faire ici.
 * Un User ne connaît ni son niveau ni ses sessions.
 *
 * C'est aussi le `UserInterface` du composant Security — pas d'adaptateur entre les
 * deux. L'identifiant de sécurité reste l'UUID et non l'e-mail : changer d'adresse
 * n'invalide aucun jeton, et l'adresse ne se promène pas dans le claim `sub`.
 * La correspondance e-mail → compte au moment du login est le travail de
 * {@see UserRepository::loadUserByIdentifier()}.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'identity_user')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const int EMAIL_MAX_LENGTH = 180;
    public const int DISPLAY_NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: self::EMAIL_MAX_LENGTH)]
    private string $email;

    /**
     * Rôles *supplémentaires*. `ROLE_USER` est implicite et n'est jamais écrit ici.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Nul pour un compte créé par social sign-in : il n'a jamais eu de mot de passe.
     * Un tel compte ne peut pas se connecter par `/api/auth/login`, et c'est voulu.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: self::DISPLAY_NAME_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::DISPLAY_NAME_MAX_LENGTH)]
    private string $displayName;

    // Le type « timezone » est écrit en toutes lettres plutôt qu'importé depuis
    // Infrastructure : il est enregistré dans config/packages/doctrine.yaml et fait
    // partie du schéma. Timezone reste un value object — contrairement à l'e-mail,
    // il porte un comportement réel (toDateTimeZone) qu'aucune contrainte ne remplace.
    #[ORM\Column(type: 'timezone', length: 64)]
    private Timezone $timezone;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $registeredAt;

    private function __construct(
        Uuid $id,
        string $email,
        string $displayName,
        Timezone $timezone,
        DateTimeImmutable $registeredAt,
    ) {
        $this->id = $id;
        $this->email = self::normalizeEmail($email);
        $this->displayName = trim($displayName);
        $this->timezone = $timezone;
        $this->registeredAt = $registeredAt;
    }

    /**
     * L'UUID v7 est généré ici, applicativement : il est triable par date de création,
     * ce qui évite une colonne d'ordre sur les gros volumes à venir.
     *
     * `$now` est fourni par l'appelant parce que le serveur possède l'horloge — aucune
     * classe du domaine ne lit l'heure toute seule, sinon plus rien n'est testable.
     *
     * Le mot de passe n'est pas un paramètre : il est haché par
     * `UserPasswordHasherInterface`, qui a besoin du User pour choisir son algorithme.
     * L'appelant enchaîne donc avec `setPassword()`.
     */
    public static function register(
        string $email,
        string $displayName,
        Timezone $timezone,
        DateTimeImmutable $now,
    ): self {
        return new self(Uuid::v7(), $email, $displayName, $timezone, $now);
    }

    /**
     * Normalisation de l'adresse : c'est elle qui donne son sens à l'index unique.
     * Sans elle, « Bob@x.fr » et « bob@x.fr » ouvriraient deux comptes.
     *
     * La RFC autorise une partie locale sensible à la casse ; aucun fournisseur
     * sérieux ne s'en sert, et la respecter coûterait plus cher en support qu'elle
     * ne rapporte.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    /**
     * Identifiant de firewall : l'UUID, pas l'e-mail. C'est ce que Lexik place dans
     * le claim `sub`, et ce que le provider reçoit à chaque requête authentifiée.
     */
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([Role::User->value, ...$this->roles]));
    }

    public function grant(Role $role): void
    {
        if (Role::User === $role || \in_array($role->value, $this->roles, true)) {
            return;
        }

        $this->roles[] = $role->value;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Reçoit un hash, jamais un mot de passe en clair — le hachage appartient à
     * `UserPasswordHasherInterface`. Sert aussi au rehash automatique déclenché par
     * Symfony au login quand l'algorithme a évolué (voir `UserRepository`).
     */
    public function setPassword(?string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function email(): string
    {
        return $this->email;
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
        $this->displayName = trim($displayName);
    }

    public function moveTo(Timezone $timezone): void
    {
        $this->timezone = $timezone;
    }
}
