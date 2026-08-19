<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un appareil joignable par notification push, identifié par son jeton Expo.
 *
 * **Le jeton appartient à l'appareil, pas au compte.** L'unicité porte sur `pushToken` seul
 * — jamais sur `(user, pushToken)` — parce qu'un téléphone qui change de compte (revente,
 * partage familial, déconnexion/reconnexion) réémet le même jeton avec un `userId`
 * différent. Le traiter comme une nouvelle ligne laisserait l'ancien compte recevoir les
 * notifications d'un appareil qui ne lui appartient plus plutôt qu'une fuite silencieuse.
 * `claim()` est donc appelée à **chaque** enregistrement, création comme réenregistrement :
 * c'est la même opération qui rend la route idempotente pour le même compte et qui
 * transfère la propriété quand ce n'est pas le cas.
 *
 * **Ne suit pas la famille de refresh tokens.** Un jeton de push survit à la rotation des
 * refresh tokens (30 jours, ré-émis à chaque appel de `/api/auth/refresh`), donc l'accrocher
 * à une famille précise l'aurait fait expirer avec elle. La route qui l'enregistre
 * authentifie par jeton d'accès (`#[CurrentUser]`), qui ne porte pas l'identifiant de
 * famille — le coupler exigerait de le faire voyager jusque dans le claim JWT, ce qu'aucun
 * autre besoin ne justifie aujourd'hui. Conséquence assumée : se déconnecter (révoquer une
 * famille) ne retire **pas** le jeton de push de cet appareil en l'état ; un compte qui se
 * déconnecte puis se reconnecte avec un autre continue de recevoir les notifications tant
 * qu'un autre appel à `claim()` ne l'a pas repris. À trancher au ticket qui branchera un
 * vrai transport (Lot 4d) : soit le client se désenregistre explicitement à la
 * déconnexion, soit `LogOutHandler` apprend à révoquer le jeton associé.
 */
#[ORM\Entity(repositoryClass: UserDeviceRepository::class)]
#[ORM\Table(name: 'identity_user_device')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_device_token', columns: ['push_token'])]
#[ORM\Index(name: 'idx_identity_user_device_user', columns: ['user_id'])]
class UserDevice
{
    /**
     * `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]` : large marge sur la vingtaine de
     * caractères usuelle, pour ne pas casser au premier format Expo qui rallonge le sien.
     */
    public const int PUSH_TOKEN_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: self::PUSH_TOKEN_MAX_LENGTH)]
    private string $pushToken;

    #[ORM\Column(length: 16, enumType: DevicePlatform::class)]
    private DevicePlatform $platform;

    #[ORM\Column(length: 16, enumType: DeviceEnvironment::class)]
    private DeviceEnvironment $environment;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $registeredAt;

    /** Rafraîchi à chaque appel de la route, y compris quand rien d'autre ne change. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    private function __construct(
        string $pushToken,
        User $user,
        DevicePlatform $platform,
        DeviceEnvironment $environment,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->pushToken = $pushToken;
        $this->registeredAt = $now;
        $this->claim($user, $platform, $environment, $now);
    }

    public static function register(
        string $pushToken,
        User $user,
        DevicePlatform $platform,
        DeviceEnvironment $environment,
        DateTimeImmutable $now,
    ): self {
        return new self($pushToken, $user, $platform, $environment, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function pushToken(): string
    {
        return $this->pushToken;
    }

    public function platform(): DevicePlatform
    {
        return $this->platform;
    }

    public function environment(): DeviceEnvironment
    {
        return $this->environment;
    }

    public function registeredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function lastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Reprend la ligne pour `$user` — sans condition sur le propriétaire actuel. Voir le
     * docblock de la classe : c'est délibérément la même opération pour un
     * réenregistrement anodin et pour un changement de propriétaire.
     */
    public function claim(User $user, DevicePlatform $platform, DeviceEnvironment $environment, DateTimeImmutable $now): void
    {
        $this->user = $user;
        $this->platform = $platform;
        $this->environment = $environment;
        $this->lastSeenAt = $now;
    }
}
