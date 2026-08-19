<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\UserDevice;
use App\Shared\Application\PushTargets;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserDevice>
 */
class UserDeviceRepository extends ServiceEntityRepository implements PushTargets
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDevice::class);
    }

    public function ofPushToken(string $pushToken): ?UserDevice
    {
        return $this->findOneBy(['pushToken' => $pushToken]);
    }

    public function add(UserDevice $device): void
    {
        $this->getEntityManager()->persist($device);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * L'implémentation du port {@see PushTargets}. Une projection scalaire du jeton et
     * rien d'autre : le consommateur n'a besoin de connaître ni l'appareil ni sa
     * plateforme pour parler à Expo, et ce qui sort d'ici ne doit donner prise sur rien de
     * plus qu'un identifiant Expo.
     */
    public function of(Uuid $userId): array
    {
        /** @var list<string> $tokens */
        $tokens = $this->createQueryBuilder('d')
            ->select('d.pushToken')
            ->where('d.user = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getSingleColumnResult();

        return $tokens;
    }
}
