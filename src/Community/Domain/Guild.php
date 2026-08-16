<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Domain\Exception\GuildIsFull;
use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un groupe de joueurs, et rien de plus en v1.
 *
 * **La guilde ne produit aucune valeur de jeu** : aucun modificateur, aucune XP, aucun
 * chemin vers `XpCalculator` ni `ModifierResolver`. Le jour où elle en produira, ce sera
 * par le vocabulaire de modificateurs existant — un `ModifierContributor` de plus — et
 * pas par une porte dérobée qui lui serait propre. C'est ce qui permet de la brancher
 * sans rouvrir le moteur.
 *
 * **Le nom n'est pas unique, et ce n'est pas un oubli.** Aucune recherche de guilde
 * n'existe : on n'y entre que par un code d'invitation (#116), donc rien ne dépend de
 * pouvoir désigner une guilde par son nom. Une contrainte globale sur un nom libre est
 * un puits à cas particuliers — casse, accents, espaces doublés, homoglyphes — qu'on
 * paierait en frustration au moment de fonder, sans rien acheter.
 *
 * L'agrégat porte ses adhésions parce que ses deux règles — le plafond et « un joueur
 * n'entre qu'une fois » — se lisent sur l'ensemble, jamais sur une ligne seule. La
 * cohérence de ce comptage face à deux `join` simultanés ne vient pas d'ici mais du
 * verrou pris sur la ligne de la guilde avant de la charger (#116).
 */
#[ORM\Entity(repositoryClass: GuildRepository::class)]
#[ORM\Table(name: 'community_guild')]
class Guild
{
    public const int NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name;

    /**
     * Le fondateur *d'origine*, figé. Il ne suit pas la succession (#118) : c'est une
     * trace de qui a créé la guilde, pas la réponse à « qui la dirige aujourd'hui » —
     * celle-là se lit sur les adhésions, et elle seule fait autorité.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * `cascade: remove` et `orphanRemoval` : dissoudre la guilde emporte ses adhésions
     * dans la même transaction, sans que l'appelant ait à les énumérer. Le
     * `onDelete: CASCADE` de la colonne dit la même chose à la base, pour le jour où une
     * ligne partirait sans passer par l'ORM.
     *
     * Ordonnées par date d'entrée : c'est l'ordre dont la succession a besoin, et la
     * moitié de celui qu'affiche la liste des membres (#117).
     *
     * @var Collection<int, GuildMembership>
     */
    #[ORM\OneToMany(targetEntity: GuildMembership::class, mappedBy: 'guild', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['joinedAt' => 'ASC'])]
    private Collection $memberships;

    private function __construct(Uuid $id, string $name, Uuid $createdBy, DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->name = trim($name);
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->memberships = new ArrayCollection();
    }

    /**
     * Fonder une guilde, c'est y entrer : la guilde et l'adhésion de son fondateur
     * naissent du même geste. Les séparer laisserait exister, ne serait-ce qu'un instant,
     * une guilde sans personne pour la dissoudre.
     */
    public static function found(string $name, Uuid $founderId, DateTimeImmutable $now): self
    {
        $guild = new self(Uuid::v7(), $name, $founderId, $now);
        $guild->memberships->add(new GuildMembership($guild, $founderId, GuildRole::Founder, $now));

        return $guild;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdBy(): Uuid
    {
        return $this->createdBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    /**
     * Accueille un joueur. Les deux refus sont ceux que seul l'agrégat peut prononcer :
     * la guilde est pleine, ou le joueur y est déjà.
     *
     * Le troisième — le joueur est dans une *autre* guilde — n'est pas ici : la guilde
     * courante ne voit pas les autres, et c'est l'index unique qui tranche. Le dépôt le
     * traduit dans le même {@see PlayerAlreadyInAGuild}, pour que l'appelant n'ait qu'un
     * cas à traiter.
     *
     * @throws GuildIsFull
     * @throws PlayerAlreadyInAGuild
     */
    public function admit(Uuid $playerId, GuildRules $rules, DateTimeImmutable $now): GuildMembership
    {
        if (null !== $this->membershipOf($playerId)) {
            throw new PlayerAlreadyInAGuild();
        }

        if ($this->memberships->count() >= $rules->maximumMembers) {
            throw new GuildIsFull($rules->maximumMembers);
        }

        $membership = new GuildMembership($this, $playerId, GuildRole::Member, $now);
        $this->memberships->add($membership);

        return $membership;
    }

    public function membershipOf(Uuid $playerId): ?GuildMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->playerId()->equals($playerId)) {
                return $membership;
            }
        }

        return null;
    }

    public function hasMember(Uuid $playerId): bool
    {
        return null !== $this->membershipOf($playerId);
    }

    public function isFoundedBy(Uuid $playerId): bool
    {
        return $this->membershipOf($playerId)?->isFounder() ?? false;
    }

    public function size(): int
    {
        return $this->memberships->count();
    }

    /**
     * Les adhésions dans l'ordre d'affichage : le fondateur d'abord, puis par date
     * d'entrée croissante.
     *
     * **Un ordre décidé, pas celui que rend la base.** Sans `ORDER BY`, PostgreSQL est
     * libre de servir les lignes comme il veut — et il change d'avis quand la table
     * grossit ou qu'un `VACUUM` passe. Une liste qui se réordonne toute seule entre deux
     * ouvertures d'écran est un bug qu'on ne sait pas reproduire.
     *
     * @return list<GuildMembership>
     */
    public function members(): array
    {
        $members = $this->memberships->toArray();

        usort($members, static fn (GuildMembership $left, GuildMembership $right): int => self::displayKey($left) <=> self::displayKey($right));

        return $members;
    }

    /**
     * L'identifiant départage, et il n'est pas décoratif : deux joueurs peuvent entrer
     * dans la même seconde, et un tri qui les laisserait ex æquo rendrait deux ordres
     * différents à deux appels identiques. L'UUID v7 est croissant dans le temps, donc
     * il prolonge la date au lieu de la contredire.
     *
     * @return array{bool, DateTimeImmutable, string}
     */
    private static function displayKey(GuildMembership $membership): array
    {
        return [!$membership->isFounder(), $membership->joinedAt(), $membership->id()->toRfc4122()];
    }
}
