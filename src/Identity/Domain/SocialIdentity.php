<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Infrastructure\Doctrine\SocialIdentityRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le lien entre un compte GRRIND et un compte chez un fournisseur d'identité.
 *
 * La clé, c'est le couple (provider, subject) — jamais l'adresse e-mail. Le `sub`
 * de Google comme celui d'Apple est stable et ne change pas quand l'utilisateur
 * change d'adresse ; l'adresse, elle, change, et chez Apple elle peut être un
 * relais privé que l'utilisateur peut désactiver.
 *
 * Un compte peut porter plusieurs identités : se connecter avec Google puis avec
 * Apple sur la même adresse vérifiée relie les deux au même joueur.
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\Table(name: 'identity_social_identity')]
#[ORM\UniqueConstraint(name: 'uniq_identity_social_provider_subject', columns: ['provider', 'subject'])]
class SocialIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: SocialProvider::class)]
    private SocialProvider $provider;

    /**
     * Le `sub` du fournisseur. Opaque, stable, propre à notre client OAuth : le
     * même humain a un `sub` différent dans une autre application.
     */
    #[ORM\Column(length: 255)]
    private string $subject;

    /**
     * L'adresse telle que le fournisseur l'a annoncée au moment du lien. Conservée
     * pour l'audit — ce n'est pas elle qui fait foi, c'est celle du compte.
     */
    #[ORM\Column(length: User::EMAIL_MAX_LENGTH, nullable: true)]
    private ?string $emailAtLinkTime;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $linkedAt;

    public function __construct(
        User $user,
        SocialProvider $provider,
        string $subject,
        ?string $emailAtLinkTime,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->emailAtLinkTime = $emailAtLinkTime;
        $this->linkedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function provider(): SocialProvider
    {
        return $this->provider;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function linkedAt(): DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
