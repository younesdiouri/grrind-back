<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Domain\NotificationCategory;
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
 * Le compte d'un joueur. Volontairement pauvre : tout ce qui relève du jeu appartient
 * aux autres modules — un User ne connaît ni son niveau ni ses séances.
 *
 * C'est aussi le `UserInterface` du firewall, sans adaptateur. L'identifiant de sécurité
 * est l'UUID et non l'e-mail : changer d'adresse n'invalide aucun jeton, et l'adresse ne
 * se promène pas dans le claim `sub`.
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
     * Nul pour un compte créé par social sign-in : il n'a jamais eu de mot de passe, et
     * ne peut donc pas passer par `/api/auth/login`.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: self::DISPLAY_NAME_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::DISPLAY_NAME_MAX_LENGTH)]
    private string $displayName;

    // Nom de type écrit en toutes lettres : il est enregistré dans doctrine.yaml et
    // fait partie du schéma. Timezone reste un VO parce qu'il porte un comportement
    // réel (toDateTimeZone) qu'aucune contrainte de validation ne remplace.
    #[ORM\Column(type: 'timezone', length: 64)]
    private Timezone $timezone;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $registeredAt;

    /**
     * Les catégories de notification que le joueur a coupées — présence, pas absence :
     * comme `$roles`, ne stocker que l'exception évite de semer une ligne « activé » pour
     * chaque compte à la sortie de chaque nouvelle catégorie. Le défaut à l'inscription
     * (#132) est donc « activé » gratuitement, sans backfill, pour toute catégorie qui
     * n'existait pas encore quand le compte a été ouvert.
     *
     * Porté par le compte et non par `UserDevice` : un joueur qui coupe `GUILD_ACTIVITY`
     * sur son iPhone ne veut pas être rattrapé sur son iPad.
     *
     * Les valeurs restent des chaînes et les inconnues sont conservées : retirer une
     * catégorie du catalogue ne réécrit pas l'historique des préférences ni ne casse un
     * rollback. Elles ne peuvent plus être créées par l'API, qui passe par
     * {@see NotificationCategory}, et ne sont jamais interprétées comme une catégorie vivante.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $disabledNotificationCategories = [];

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
     * Le mot de passe n'est pas un paramètre : `UserPasswordHasherInterface` a besoin du
     * User pour choisir son algorithme, donc l'appelant enchaîne avec `setPassword()`.
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
     * Ce qui donne son sens à l'index unique : sans normalisation, « Bob@x.fr » et
     * « bob@x.fr » ouvriraient deux comptes. La RFC autorise une partie locale sensible
     * à la casse ; aucun fournisseur sérieux ne s'en sert.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    /** L'UUID, pas l'e-mail : c'est ce que Lexik place dans le claim `sub`. */
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
     * Reçoit un hash, jamais un mot de passe en clair. Sert aussi au rehash opportuniste
     * déclenché par Symfony au login — voir `UserRepository::upgradePassword()`.
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

    /**
     * Le joueur veut-il être notifié pour cette catégorie — lu par
     * {@see \App\Identity\Infrastructure\Doctrine\UserDeviceRepository::of()} pour rendre
     * une liste de cibles vide plutôt qu'une liste que l'appelant devrait filtrer.
     */
    public function notifiesOn(NotificationCategory $category): bool
    {
        return !\in_array($category->value, $this->disabledNotificationCategories, true);
    }

    public function setNotificationPreference(NotificationCategory $category, bool $enabled): void
    {
        if ($enabled) {
            $this->disabledNotificationCategories = array_values(
                array_diff($this->disabledNotificationCategories, [$category->value]),
            );

            return;
        }

        if (!\in_array($category->value, $this->disabledNotificationCategories, true)) {
            $this->disabledNotificationCategories[] = $category->value;
        }
    }
}
