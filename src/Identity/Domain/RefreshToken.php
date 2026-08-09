<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton de rafraîchissement, à usage unique et rotatif.
 *
 * Chaque échange consomme le jeton présenté et en émet un nouveau dans la même
 * *famille*. Une famille correspond à un appareil connecté : s'y déconnecter la
 * révoque entièrement.
 *
 * Le rejeu d'un jeton déjà consommé est traité comme un vol, pas comme une erreur
 * de client : soit l'attaquant a doublé le vrai client, soit l'inverse, et on n'a
 * aucun moyen de les distinguer. La seule réponse sûre est de révoquer la famille
 * et de forcer un vrai login.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_refresh_token')]
#[ORM\UniqueConstraint(name: 'uniq_identity_refresh_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_identity_refresh_token_family', columns: ['family_id'])]
class RefreshToken
{
    /**
     * Trente jours : assez pour qu'un joueur régulier ne se reconnecte jamais,
     * assez court pour qu'un appareil oublié finisse par sortir.
     */
    public const string LIFETIME = 'P30D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Identifie la lignée de rotations issue d'un même login.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $familyId;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    private function __construct(User $user, Uuid $familyId, RefreshTokenSecret $secret, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->familyId = $familyId;
        $this->tokenHash = $secret->hash();
        $this->issuedAt = $now;
        $this->expiresAt = $now->add(new DateInterval(self::LIFETIME));
    }

    /**
     * Premier jeton d'une famille : un login, un appareil.
     */
    public static function startFamily(User $user, RefreshTokenSecret $secret, DateTimeImmutable $now): self
    {
        return new self($user, Uuid::v7(), $secret, $now);
    }

    /**
     * Successeur du jeton courant, dans la même famille.
     */
    public function rotate(RefreshTokenSecret $secret, DateTimeImmutable $now): self
    {
        $this->consumedAt = $now;

        return new self($this->user, $this->familyId, $secret, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function familyId(): Uuid
    {
        return $this->familyId;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt
            && null === $this->revokedAt
            && $now < $this->expiresAt;
    }

    /**
     * Un jeton déjà consommé ou révoqué qu'on nous représente : ce n'est plus une
     * expiration banale, c'est le signal qu'une copie circule.
     */
    public function isReplay(): bool
    {
        return null !== $this->consumedAt || null !== $this->revokedAt;
    }
}
