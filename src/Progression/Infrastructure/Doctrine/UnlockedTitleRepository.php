<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\Title;
use App\Progression\Domain\UnlockedTitle;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Écrire un déblocage, lire ceux d'un joueur. **Pas de retrait** : l'absence de méthode est
 * la garantie, au même titre que pour le ledger d'XP — un dépôt qui sait défaire finit par
 * défaire.
 *
 * @extends ServiceEntityRepository<UnlockedTitle>
 */
class UnlockedTitleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnlockedTitle::class);
    }

    /**
     * Ce que ce joueur a déjà, daté. Une seule lecture sert les trois usages : savoir ce
     * qu'il ne faut plus évaluer, afficher la date de déblocage au mur des titres, et
     * refuser d'afficher un titre qu'on n'a pas.
     *
     * @return array<string, DateTimeImmutable> identifiant de titre → date de déblocage
     */
    public function unlockedBy(Uuid $userId): array
    {
        $unlocked = [];

        foreach ($this->findBy(['userId' => $userId]) as $title) {
            $unlocked[$title->titleId()] = $title->unlockedAt();
        }

        return $unlocked;
    }

    public function record(Uuid $userId, Title $title, DateTimeImmutable $now): void
    {
        $this->getEntityManager()->persist(new UnlockedTitle($userId, $title, $now));
    }

    public function holds(Uuid $userId, string $titleId): bool
    {
        return null !== $this->find(['userId' => $userId, 'titleId' => $titleId]);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
