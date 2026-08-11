<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\ActiveTitleRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le titre que le joueur a choisi d'afficher. Une ligne par compte, ou aucune — ne rien
 * porter est un choix, pas un manque.
 *
 * **Une table séparée de `player_title`, et pas une colonne dessus.** Les deux données n'ont
 * ni la même durée de vie ni la même nature : le déblocage est un fait acquis, définitif ;
 * la sélection est une préférence, qui change autant que le joueur veut. Les mêler
 * imposerait un index partiel « un seul actif par joueur » et deux `UPDATE` ordonnés pour en
 * changer, là où une ligne remplacée d'un `INSERT … ON CONFLICT` suffit.
 *
 * Le lien entre les deux tables est une **clé étrangère composée** sur (user_id, title_id),
 * posée dans la migration : afficher un titre non débloqué devient impossible au niveau de
 * la base, pas seulement au niveau du code. La vérification applicative existe quand même —
 * elle produit un message que le joueur comprend, là où la base produit une erreur 500.
 */
#[ORM\Entity(repositoryClass: ActiveTitleRepository::class)]
#[ORM\Table(name: 'player_active_title')]
class ActiveTitle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column(length: UnlockedTitle::TITLE_ID_MAX_LENGTH)]
    private string $titleId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $selectedAt;

    public function __construct(Uuid $userId, string $titleId, DateTimeImmutable $selectedAt)
    {
        $this->userId = $userId;
        $this->titleId = $titleId;
        $this->selectedAt = $selectedAt;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function titleId(): string
    {
        return $this->titleId;
    }

    public function selectedAt(): DateTimeImmutable
    {
        return $this->selectedAt;
    }
}
