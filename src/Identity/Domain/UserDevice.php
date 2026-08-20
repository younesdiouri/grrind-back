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
 * **Suit la famille de refresh tokens (#136, arbitrage B).** Une famille *est* un appareil —
 * `CLAUDE.md` le disait déjà avant que ce ticket en tire la conséquence : porter le jeton de
 * push par autre chose qu'elle aurait été une deuxième définition d'appareil dans le même
 * projet. `familyId` vient du claim `fid` du jeton d'accès courant ({@see
 * \App\Identity\UI\Http\CurrentDeviceFamily}) et `claim()` le réécrit à **chaque** appel, au
 * même titre que le propriétaire — pas seulement à la création : un même téléphone qui se
 * déconnecte puis se reconnecte sur le même compte ouvre une famille neuve à chaque login, et
 * seule la dernière doit pouvoir couper ce jeton. `LogOutHandler` et le rejeu détecté par
 * `RefreshSessionHandler` retirent la ligne dont ils révoquent la famille, dans la même
 * transaction — c'est ce qui referme la fuite que ce docblock décrivait jusqu'ici.
 *
 * **Nullable.** Les lignes déjà en base au déploiement de ce ticket n'ont pas de famille, et
 * un jeton d'accès signé juste avant le déploiement reste valable jusqu'à quinze minutes après
 * sans porter le claim — dans les deux cas `claim()` reçoit `null` et l'écrit tel quel plutôt
 * que d'échouer, la ligne se raccroche à une vraie famille au premier appel qui en porte une.
 * Une famille qui pointe une lignée révoquée n'est pas nettoyée pour autant : elle se fait
 * reprendre au prochain `claim()`, et #131 ramasse la ligne si l'appareil est vraiment parti.
 */
#[ORM\Entity(repositoryClass: UserDeviceRepository::class)]
#[ORM\Table(name: 'identity_user_device')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_device_token', columns: ['push_token'])]
#[ORM\Index(name: 'idx_identity_user_device_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_identity_user_device_family', columns: ['family_id'])]
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

    /** La famille de refresh tokens dont vient le jeton d'accès qui a fait ce `claim()`. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $familyId;

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
        ?Uuid $familyId,
        DevicePlatform $platform,
        DeviceEnvironment $environment,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->pushToken = $pushToken;
        $this->registeredAt = $now;
        $this->claim($user, $familyId, $platform, $environment, $now);
    }

    public static function register(
        string $pushToken,
        User $user,
        ?Uuid $familyId,
        DevicePlatform $platform,
        DeviceEnvironment $environment,
        DateTimeImmutable $now,
    ): self {
        return new self($pushToken, $user, $familyId, $platform, $environment, $now);
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

    public function familyId(): ?Uuid
    {
        return $this->familyId;
    }

    /**
     * Reprend la ligne pour `$user` — sans condition sur le propriétaire actuel. Voir le
     * docblock de la classe : c'est délibérément la même opération pour un
     * réenregistrement anodin et pour un changement de propriétaire, et `$familyId` suit
     * exactement la même règle que `$user`.
     */
    public function claim(User $user, ?Uuid $familyId, DevicePlatform $platform, DeviceEnvironment $environment, DateTimeImmutable $now): void
    {
        $this->user = $user;
        $this->familyId = $familyId;
        $this->platform = $platform;
        $this->environment = $environment;
        $this->lastSeenAt = $now;
    }
}
