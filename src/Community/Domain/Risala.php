<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Domain\Exception\DisciplineAlreadyChallenged;
use App\Community\Domain\Exception\DisciplineDoesNotCredit;
use App\Community\Domain\Exception\RisalaTurnIsClosed;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une **Risāla** : le défi sportif qu'un membre envoie à toute sa guilde. Une discipline, et
 * deux semaines pour la caler dans son emploi du temps.
 *
 * ## Un tour et une Risāla sont la même ligne
 *
 * Le tirage crée la ligne avant qu'aucune discipline soit choisie : un tour est une Risāla
 * en gestation, et un tour manqué est une Risāla qui n'est jamais partie. Deux tables
 * raconteraient deux fois la même histoire, et il faudrait alors se souvenir laquelle fait
 * autorité sur « qui a déjà envoyé » — qui est précisément la question de la rotation.
 *
 * C'est aussi ce qui fait qu'un tour manqué **compte** dans le cycle : il est là, avec son
 * `cycle` et son expéditeur, et {@see RisalaRotation} ne fait pas la différence. Un membre
 * passif coûte une semaine à sa guilde, jamais le blocage du cycle entier.
 *
 * ## Trois instants, et un seul les gouverne
 *
 * `deadline` est un point de la grille hebdomadaire ({@see RisalaRules}) : c'est à la fois
 * la limite pour choisir **et** l'instant de la révélation. La Risāla est donc datée par sa
 * grille, jamais par l'horloge de la bascule qui l'a scellée — sans quoi une bascule
 * exécutée cinq secondes après l'heure décalerait l'expiration de cinq secondes, et il
 * existerait un court moment à trois Risālāt vivantes à chaque semaine.
 *
 * L'instant réel de l'écriture n'est pas perdu pour autant : il est dans l'UUID v7 de la
 * ligne, comme partout ailleurs dans le projet.
 *
 * ## Le tirage est audité
 *
 * `drawRoll` et `drawPoolSize` gardent la trace de ce qui a été tiré et parmi combien. Même
 * exigence que pour le loot (#28) : un tirage serveur qui ne se raconte pas ne se défend
 * pas, et « pourquoi jamais moi ? » est une question qu'on nous posera avant la fin du
 * premier mois.
 */
#[ORM\Entity(repositoryClass: RisalaRepository::class)]
#[ORM\Table(name: 'community_risala')]
// Au plus un tour ouvert par guilde, et c'est la base qui le tient — pas un `if` dans la
// bascule. Index unique **partiel** : la contrainte ne porte que sur `DRAWN`, puisqu'une
// guilde accumule autant de `SENT` et de `MISSED` qu'elle a de semaines derrière elle.
#[ORM\UniqueConstraint(name: 'uniq_community_risala_open_turn', columns: ['guild_id'], options: ['where' => "(status = 'DRAWN')"])]
// Nommé ici autant que dans la migration : sans ça, le diff propose de le renommer en son
// hash à chaque génération, et ce faux positif revient à chaque ticket. Sur `guild_id` seul
// et non sur (guild_id, revealed_at, expires_at) : toutes les lectures partent de la guilde,
// et une guilde compte une Risāla par semaine — le tri des quelques lignes restantes ne
// mérite pas un index composite à maintenir.
#[ORM\Index(name: 'idx_community_risala_guild', columns: ['guild_id'])]
class Risala
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Guild::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    /**
     * Le membre tiré. Un `Uuid` et non une `GuildMembership` : il peut avoir quitté la
     * guilde depuis, et l'histoire de la rotation ne doit pas disparaître avec lui.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $senderId;

    /** Le tour de rotation auquel ce tirage appartient — voir {@see RisalaRotation}. */
    #[ORM\Column]
    private int $cycle;

    /** `null` tant que rien n'a été choisi, et pour toujours si le tour est manqué. */
    #[ORM\Column(length: 16, nullable: true, enumType: Discipline::class)]
    private ?Discipline $discipline = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $drawnAt;

    /** Le point de grille : limite pour choisir **et** instant de la révélation. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $deadline;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $chosenAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revealedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 16, enumType: RisalaStatus::class)]
    private RisalaStatus $status;

    #[ORM\Column]
    private int $drawRoll;

    #[ORM\Column]
    private int $drawPoolSize;

    private function __construct(Guild $guild, Uuid $senderId, int $cycle, DateTimeImmutable $drawnAt, DateTimeImmutable $deadline, int $drawRoll, int $drawPoolSize)
    {
        $this->id = Uuid::v7();
        $this->guild = $guild;
        $this->senderId = $senderId;
        $this->cycle = $cycle;
        $this->drawnAt = $drawnAt;
        $this->deadline = $deadline;
        $this->status = RisalaStatus::Drawn;
        $this->drawRoll = $drawRoll;
        $this->drawPoolSize = $drawPoolSize;
    }

    /**
     * Ouvre un tour. La discipline viendra — ou ne viendra pas.
     *
     * Le tirage proprement dit se décide dans {@see RisalaRotation}, qui voit tous les
     * membres et tout le cycle ; ici on n'enregistre que son résultat et de quoi le rejouer.
     */
    public static function draw(Guild $guild, RisalaRotation $rotation, int $roll, DateTimeImmutable $drawnAt, DateTimeImmutable $deadline): self
    {
        return new self($guild, $rotation->drawnBy($roll), $rotation->cycle, $drawnAt, $deadline, $roll, \count($rotation->pool));
    }

    /**
     * Le membre tiré arrête son choix. Réversible tant que l'échéance n'est pas passée : on
     * change d'avis sur un sport qu'on propose aux autres, et rien ne rend ce revirement
     * coûteux pour qui que ce soit.
     *
     * Les trois refus sont ici parce que le domaine seul peut les prononcer — le troisième
     * demande l'état des Risālāt vivantes, que l'appelant lui apporte.
     *
     * @param list<Discipline> $challenged les disciplines des Risālāt vivantes de la guilde
     *
     * @throws RisalaTurnIsClosed
     * @throws DisciplineDoesNotCredit
     * @throws DisciplineAlreadyChallenged
     */
    public function choose(Discipline $discipline, CreditingDisciplines $crediting, array $challenged, DateTimeImmutable $now): void
    {
        if (RisalaStatus::Drawn !== $this->status || $now >= $this->deadline) {
            throw new RisalaTurnIsClosed(\sprintf('Le tour s\'est refermé le %s.', $this->deadline->format('c')));
        }

        if (!$crediting->credits($discipline)) {
            throw new DisciplineDoesNotCredit($discipline);
        }

        if (\in_array($discipline, $challenged, true)) {
            throw new DisciplineAlreadyChallenged($discipline);
        }

        $this->discipline = $discipline;
        $this->chosenAt = $now;
    }

    /**
     * L'échéance est atteinte : le tour produit une Risāla, ou il est perdu. Dans les deux
     * cas il est **consommé** — la rotation avance.
     *
     * `$senderIsStillAMember` est le second refus, et il n'est pas décoratif : une Risāla est
     * envoyée *par un membre*, et celui qui est parti n'envoie plus rien. Le laisser défier
     * une guilde qu'il a quittée ferait vivre son choix deux semaines de plus que lui.
     *
     * La révélation est datée par `deadline` et non par l'horloge de la bascule — voir le
     * docblock de la classe.
     */
    public function seal(RisalaRules $rules, bool $senderIsStillAMember): void
    {
        if (RisalaStatus::Drawn !== $this->status) {
            return;
        }

        if (null === $this->discipline || !$senderIsStillAMember) {
            $this->status = RisalaStatus::Missed;

            // La discipline choisie est effacée : elle n'a jamais été annoncée à personne,
            // et la laisser ferait croire à une Risāla que la guilde aurait reçue.
            $this->discipline = null;

            return;
        }

        $this->status = RisalaStatus::Sent;
        $this->revealedAt = $this->deadline;
        $this->expiresAt = $rules->expiryOf($this->deadline);
    }

    /** Le tour est-il échu ? La bascule ne demande rien d'autre pour savoir quoi faire. */
    public function isDueAt(DateTimeImmutable $at): bool
    {
        return RisalaStatus::Drawn === $this->status && $at >= $this->deadline;
    }

    /**
     * Vivante à cet instant-là. Bornes mi-ouvertes, et c'est ce qui garantit qu'il y en a
     * exactement deux : celle de la semaine N−2 expire à la seconde où celle de N naît, et
     * aucune des deux ne compte deux fois.
     */
    public function isLiveAt(DateTimeImmutable $at): bool
    {
        return null !== $this->revealedAt
            && null !== $this->expiresAt
            && $at >= $this->revealedAt
            && $at < $this->expiresAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function guild(): Guild
    {
        return $this->guild;
    }

    public function senderId(): Uuid
    {
        return $this->senderId;
    }

    public function cycle(): int
    {
        return $this->cycle;
    }

    public function discipline(): ?Discipline
    {
        return $this->discipline;
    }

    public function deadline(): DateTimeImmutable
    {
        return $this->deadline;
    }

    public function revealedAt(): ?DateTimeImmutable
    {
        return $this->revealedAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): RisalaStatus
    {
        return $this->status;
    }
}
