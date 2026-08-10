<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency;

use App\Shared\Infrastructure\Doctrine\IdempotencyRecordRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La trace d'une écriture métier déjà tentée : « cette clé, pour ce joueur, portait
 * cette requête, et voici ce qu'on lui a répondu ».
 *
 * Elle existe parce qu'un client mobile rejoue : réseau coupé, reprise en arrière-plan,
 * retry système. Sans elle, une séance terminée deux fois est une séance créditée deux
 * fois — ou, pire, un 409 rendu à un joueur dont l'action avait pourtant réussi.
 *
 * La réponse conservée est un contenu **opaque** : un entier, quelques en-têtes et un
 * corps. Le record ne sait pas ce qu'il rejoue, et c'est ce qui le rend transverse.
 *
 * L'unicité porte sur (user, clé) et non sur la clé seule : deux joueurs peuvent tirer
 * la même valeur, et surtout une clé interceptée ne doit jamais donner accès à la
 * réponse de quelqu'un d'autre.
 *
 * @see IdempotencyRecordRepository pour la raison — bien réelle — des écritures en DBAL
 */
#[ORM\Entity(repositoryClass: IdempotencyRecordRepository::class)]
#[ORM\Table(name: 'shared_idempotency_key')]
#[ORM\UniqueConstraint(name: 'uniq_shared_idempotency_user_key', columns: ['user_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_shared_idempotency_expires', columns: ['expires_at'])]
class IdempotencyRecord
{
    /**
     * Vingt-quatre heures : au-delà, un client qui rejoue ne rejoue plus, il refait.
     * La purge des expirées est un travail de fond, ticketé au Lot 9 ; d'ici là une
     * clé périmée est simplement réutilisable, personne ne la lit.
     */
    public const string LIFETIME = 'PT24H';

    public const int KEY_MAX_LENGTH = 255;

    /**
     * SHA-256 en hexadécimal de la méthode, du chemin et du corps de la requête.
     */
    public const int FINGERPRINT_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    // Nommée en toutes lettres plutôt que « key », qui est un mot-clé SQL dans assez
    // de moteurs pour que la question se repose à chaque outil qui lit le schéma.
    #[ORM\Column(name: 'idempotency_key', length: self::KEY_MAX_LENGTH)]
    private string $key;

    #[ORM\Column(length: self::FINGERPRINT_LENGTH)]
    private string $requestFingerprint;

    #[ORM\Column(length: 16, enumType: RecordStatus::class)]
    private RecordStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $responseStatus = null;

    /**
     * Une liste blanche, pas la totalité des en-têtes : rejouer un `Date` d'hier ou
     * un jeton de session périmé ferait plus de dégâts que de ne rien rejouer.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $responseHeaders = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    private function __construct(
        Uuid $userId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->key = $key;
        $this->requestFingerprint = $requestFingerprint;
        $this->status = RecordStatus::InFlight;
        $this->createdAt = $now;
        $this->expiresAt = $now->add(new DateInterval(self::LIFETIME));
    }

    /**
     * La réservation qu'une requête tente de poser. C'est ici, et pas dans le dépôt,
     * que se décident l'identifiant et la date de péremption : la durée de rétention
     * est une règle du mécanisme, pas un détail de la commande SQL qui l'écrit.
     *
     * Le dépôt lit ensuite cet objet pour composer son `INSERT` — il ne repasse pas
     * par l'ORM, et {@see IdempotencyRecordRepository} dit pourquoi.
     */
    public static function reserve(
        Uuid $userId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
    ): self {
        return new self($userId, $key, $requestFingerprint, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): RecordStatus
    {
        return $this->status;
    }

    public function requestFingerprint(): string
    {
        return $this->requestFingerprint;
    }

    /**
     * Même clé, même requête ? C'est la question qui sépare le rejeu légitime — on
     * rend la réponse d'origine — de la clé recyclée sur un autre contenu, qu'on refuse.
     */
    public function covers(string $requestFingerprint): bool
    {
        return hash_equals($this->requestFingerprint, $requestFingerprint);
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(): array
    {
        return $this->responseHeaders ?? [];
    }

    public function responseBody(): string
    {
        return $this->responseBody ?? '';
    }
}
