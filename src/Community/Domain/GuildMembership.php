<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'appartenance d'un joueur à une guilde.
 *
 * **L'index unique porte sur le joueur seul, pas sur le couple (guilde, joueur).** Un
 * index sur le couple n'interdirait que d'entrer deux fois dans la *même* guilde, ce qui
 * n'est pas la règle : un joueur n'appartient qu'à une guilde, point. C'est la base qui
 * le garantit et non un `if` dans un handler, parce qu'entre le `SELECT` qui vérifie et
 * l'`INSERT` qui écrit, deux requêtes concurrentes passent toutes les deux.
 *
 * Le joueur est un `Uuid` et non une entité `User` : `Identity` appartient à un autre
 * module, et Deptrac interdit la flèche. Ce qu'il faut afficher de lui arrive par les
 * ports de `Shared\Application` (#117).
 */
#[ORM\Entity(repositoryClass: GuildMembershipRepository::class)]
#[ORM\Table(name: 'community_guild_membership')]
#[ORM\UniqueConstraint(name: 'uniq_community_membership_player', columns: ['player_id'])]
class GuildMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Guild::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $playerId;

    #[ORM\Column(length: 16, enumType: GuildRole::class)]
    private GuildRole $role;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $joinedAt;

    /**
     * Interne à l'agrégat : une adhésion ne se crée que par {@see Guild::found()} ou
     * {@see Guild::admit()}, qui sont les seuls à connaître le plafond et le rôle dû.
     *
     * @internal
     */
    public function __construct(Guild $guild, Uuid $playerId, GuildRole $role, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->guild = $guild;
        $this->playerId = $playerId;
        $this->role = $role;
        $this->joinedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function guild(): Guild
    {
        return $this->guild;
    }

    public function playerId(): Uuid
    {
        return $this->playerId;
    }

    public function role(): GuildRole
    {
        return $this->role;
    }

    public function isFounder(): bool
    {
        return GuildRole::Founder === $this->role;
    }

    public function joinedAt(): DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /** @internal la succession se décide dans {@see Guild}, qui voit toutes les adhésions */
    public function promoteToFounder(): void
    {
        $this->role = GuildRole::Founder;
    }
}
