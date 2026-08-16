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

    /**
     * Les dates de déblocage de plusieurs joueurs pour un petit jeu de titres, **en une
     * requête**, indexées par « UUID\0identifiant ».
     *
     * Les deux listes sont croisées en SQL puis appariées en PHP : DQL ne sait pas
     * paramétrer un `IN` sur des tuples, et le sur-ensemble ramené est minuscule — l'appelant
     * ne demande que les titres réellement portés, soit une poignée de valeurs distinctes
     * quelle que soit la taille de la guilde. Filtrer sur les seuls joueurs ramènerait tout
     * leur mur des titres pour n'en garder qu'une ligne chacun.
     *
     * @param list<Uuid>   $userIds
     * @param list<string> $titleIds
     *
     * @return array<string, DateTimeImmutable>
     */
    public function unlockedAtOf(array $userIds, array $titleIds): array
    {
        if ([] === $userIds || [] === $titleIds) {
            return [];
        }

        /** @var list<array{userId: Uuid, titleId: string, unlockedAt: DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.userId', 't.titleId', 't.unlockedAt')
            ->where('t.userId IN (:userIds)')
            ->andWhere('t.titleId IN (:titleIds)')
            ->setParameter('userIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds))
            ->setParameter('titleIds', $titleIds)
            ->getQuery()
            ->getResult();

        $unlocked = [];

        foreach ($rows as $row) {
            $unlocked[self::pairKey($row['userId'], $row['titleId'])] = $row['unlockedAt'];
        }

        return $unlocked;
    }

    /**
     * La clé du couple, écrite ici pour que le dépôt et son appelant ne puissent pas en
     * avoir deux versions. Séparateur nul : il ne peut apparaître ni dans un UUID ni dans
     * un identifiant de titre, alors qu'un tiret, si.
     */
    public static function pairKey(Uuid $userId, string $titleId): string
    {
        return $userId->toRfc4122()."\0".$titleId;
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
