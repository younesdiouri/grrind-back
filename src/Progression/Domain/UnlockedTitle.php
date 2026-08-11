<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * « Ce joueur a débloqué ce titre, ce jour-là. » **Un titre débloqué ne se reprend jamais** —
 * c'est l'exigence du ticket, et c'est cette table qui la porte : aucun mutateur, aucune
 * méthode de retrait au dépôt, rien qui puisse défaire une ligne.
 *
 * La conséquence est voulue : une séance invalidée fait redescendre les compteurs du relevé
 * sous le seuil, et le titre reste. Un joueur qui a couru cent fois l'a fait, même si l'une
 * de ces séances a été annulée ensuite. C'est le contraire de ce qu'on veut pour l'XP, et
 * pour la même raison : l'XP est une monnaie, un titre est un souvenir.
 *
 * Pas d'identifiant propre : le couple (joueur, titre) *est* la clé, et c'est cette clé
 * primaire qui rend le déblocage idempotent — deux évaluations concurrentes ne peuvent pas
 * écrire deux fois le même titre.
 *
 * `titleId` n'est pas une clé étrangère : le catalogue est du config-as-code, il n'a pas de
 * table. Un titre retiré du YAML laisse donc des lignes orphelines — délibérément. Elles ne
 * s'affichent plus, et elles reviennent intactes le jour où l'on remet le titre.
 */
#[ORM\Entity(repositoryClass: UnlockedTitleRepository::class)]
#[ORM\Table(name: 'player_title')]
class UnlockedTitle
{
    public const int TITLE_ID_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Id]
    #[ORM\Column(length: self::TITLE_ID_MAX_LENGTH)]
    private string $titleId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $unlockedAt;

    public function __construct(Uuid $userId, Title $title, DateTimeImmutable $unlockedAt)
    {
        $this->userId = $userId;
        $this->titleId = $title->id;
        $this->unlockedAt = $unlockedAt;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function titleId(): string
    {
        return $this->titleId;
    }

    public function unlockedAt(): DateTimeImmutable
    {
        return $this->unlockedAt;
    }
}
