<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\DeviceEnvironment;
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
    private readonly DeviceEnvironment $currentEnvironment;

    /**
     * `$kernelEnvironment` est `%kernel.environment%`, câblé dans `services.yaml` —
     * `dev`/`test`/`prod`, jamais un `DeviceEnvironment`. La traduction est
     * {@see DeviceEnvironment::ofRuntimeEnvironment()}, voir {@see of()} pour pourquoi
     * elle a lieu ici et une seule fois.
     */
    public function __construct(ManagerRegistry $registry, string $kernelEnvironment)
    {
        parent::__construct($registry, UserDevice::class);
        $this->currentEnvironment = DeviceEnvironment::ofRuntimeEnvironment($kernelEnvironment);
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
     *
     * **Filtre sur l'environnement courant.** Sans ce filtre, `DeviceEnvironment` serait un
     * champ stocké et jamais lu : un notifier de production enverrait ses campagnes aux
     * jetons `DEVELOPMENT` des développeurs, et devoir filtrer chez l'appelant est un oubli
     * qui finit toujours par arriver. C'est une règle de plateforme, tranchée ici et une
     * seule fois plutôt que chez chaque consommateur du port.
     */
    public function of(Uuid $userId): array
    {
        /** @var list<string> $tokens */
        $tokens = $this->createQueryBuilder('d')
            ->select('d.pushToken')
            ->where('d.user = :userId')
            ->andWhere('d.environment = :environment')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('environment', $this->currentEnvironment)
            ->getQuery()
            ->getSingleColumnResult();

        return $tokens;
    }
}
