<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\GuildInviteCodeRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\String\ByteString;
use Symfony\Component\Uid\Uuid;

/**
 * Le laissez-passer d'une guilde. **Il remplace l'annuaire au lieu de le compléter** :
 * aucun `handle` unique n'existe, `displayName` n'est pas unique, et une recherche par
 * adresse exacte rendrait l'API capable de confirmer qu'une adresse a un compte —
 * exactement ce que `json_login` s'échine à empêcher. Le code se partage hors de l'app,
 * et seul un joueur déjà inscrit et connecté peut le consommer.
 *
 * **Trois états, et surtout pas une machine à états.** La question s'est posée pour le
 * composant Workflow. Elle ne paie pas ici : « expiré » n'est déclenché par personne, une
 * horloge passe. Un `marking` en colonne serait périmé par construction — un code expiré
 * resterait marqué actif tant qu'aucun `apply()` ne passe — et il faudrait une tâche de
 * fond pour tenir la colonne honnête, ou relire `expiresAt` de toute façon. Deux colonnes
 * qui ne peuvent pas mentir valent mieux qu'une troisième qui le peut : `expiresAt` est
 * une date, `revokedAt` un acte, et {@see self::isUsableAt()} est la seule lecture.
 */
#[ORM\Entity(repositoryClass: GuildInviteCodeRepository::class)]
#[ORM\Table(name: 'community_guild_invite_code')]
#[ORM\UniqueConstraint(name: 'uniq_community_invite_code', columns: ['code'])]
#[ORM\Index(name: 'idx_community_invite_code_guild', columns: ['guild_id'])]
class GuildInviteCode
{
    /**
     * Huit caractères sur un alphabet de trente et un : de quoi rendre le tirage au hasard
     * sans intérêt (≈ 8,5 × 10¹¹ combinaisons) tout en restant dictable à voix haute et
     * recopiable sans faute.
     */
    public const int LENGTH = 8;

    /**
     * L'alphabet, **amputé de ce qui se confond** : ni `O` ni `0`, ni `I`, `L` ou `1`. Un
     * code se lit au téléphone ou se recopie d'une capture d'écran, et un `0` pris pour un
     * `O` produit un « code invalide » que personne ne sait diagnostiquer. Les majuscules
     * seules, pour la même raison — et parce que le clavier iOS met une majuscule d'office
     * en début de champ.
     */
    public const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Guild::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\Column(length: self::LENGTH)]
    private string $code;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    /**
     * `null` tant que le code n'a pas été révoqué. **C'est cette colonne que porte l'index
     * unique partiel** (`WHERE revoked_at IS NULL`) : elle est ce qui garantit qu'une
     * guilde n'a jamais deux codes vivants à la fois, sans qu'aucun handler ait à le
     * vérifier.
     *
     * Les codes révoqués restent en base. Ils ne coûtent rien, et les effacer ferait
     * perdre la trace de ce qui a circulé.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    private function __construct(Guild $guild, string $code, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->guild = $guild;
        $this->code = $code;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
    }

    public static function issueFor(Guild $guild, DateInterval $lifetime, DateTimeImmutable $now): self
    {
        return new self($guild, self::draw(), $now, $now->add($lifetime));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function guild(): Guild
    {
        return $this->guild;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function issuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * **La seule lecture d'état, et elle ne distingue pas ses deux causes.** Un code
     * révoqué et un code expiré sont également inutilisables ; l'appelant n'a pas à savoir
     * lequel, et surtout la route ne doit pas le lui dire — voir
     * {@see Exception\InviteCodeNotUsable}.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $now < $this->expiresAt;
    }

    /**
     * Idempotent : révoquer deux fois garde la première date. Ce qui compte est que le
     * code soit mort, pas quand on l'a redit.
     */
    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    /**
     * Le tirage. **`Randomizer` plutôt qu'une boucle sur `random_bytes`**, et c'est la
     * règle n°0 qui tranche : les deux puisent à la même source (le moteur `Secure` de
     * PHP, celui de `random_bytes`), mais `getBytesFromString` fait en plus le rejet des
     * valeurs qui débordent le dernier tour complet de l'alphabet. Trente et un ne divise
     * pas 256 : un `ord($octet) % 31` écrit à la main rendrait les huit premières lettres
     * un tiers plus probables que les autres, ce qui divise l'espace réel de recherche
     * sans que rien ne le signale. `rand()` et `uniqid()` sont hors sujet — ni l'un ni
     * l'autre n'est cryptographique.
     *
     * @see https://symfony.com/doc/current/components/string.html
     */
    private static function draw(): string
    {
        return ByteString::fromRandom(self::LENGTH, self::ALPHABET)->toString();
    }
}
