<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\DeviceEnvironment;
use App\Identity\Domain\User;
use App\Identity\Domain\UserDevice;
use App\Shared\Application\DeadPushTokens;
use App\Shared\Application\PushTargets;
use App\Shared\Domain\NotificationCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserDevice>
 */
class UserDeviceRepository extends ServiceEntityRepository implements PushTargets, DeadPushTokens
{
    /**
     * `$targetEnvironment` vient du réglage `PUSH_TARGET_ENVIRONMENT` (#149), câblé dans
     * `services.yaml` via le processeur `enum:` de Symfony — jamais de
     * `%kernel.environment%` : quel canal APNs un jeton porte et quel déploiement ce
     * serveur adresse sont deux questions différentes, voir le docblock de
     * {@see DeviceEnvironment}. `PRODUCTION` est le défaut de `.env`, y compris pour un
     * back de dev.
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly DeviceEnvironment $targetEnvironment,
        private readonly LoggerInterface $logger,
    ) {
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
     * Retire l'appareil de la famille qu'on révoque — `LogOutHandler` et le rejeu détecté par
     * `RefreshSessionHandler` (#136). Au plus une ligne par famille en pratique (voir le
     * docblock de {@see UserDevice} : `claim()` réécrit `familyId` à
     * chaque appel), donc un `DELETE` en DQL plutôt qu'un aller-retour par l'unit of work —
     * même idiome que {@see RefreshTokenRepository::revokeFamily()}, dont l'appelant doit
     * l'exécuter dans la même transaction.
     */
    public function discardFamily(Uuid $familyId): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM '.UserDevice::class.' d WHERE d.familyId = :family'
        )
            ->setParameter('family', $familyId, UuidType::NAME)
            ->execute();
    }

    /**
     * L'implémentation du port {@see DeadPushTokens}. Sèche et immédiate — pas de flag,
     * pas de compteur d'échecs à côté : Expo est formel sur `DeviceNotRegistered`, la
     * ligne n'a plus de raison d'exister. `ofPushToken()` puis `remove()` plutôt qu'un
     * DQL `DELETE` : le volume d'un refus par envoi ne justifie pas de contourner l'unit
     * of work, et ça garde `claim()` (un appel concurrent qui viendrait de réclamer ce
     * jeton pour un autre compte) visible au flush plutôt qu'écrasé silencieusement.
     */
    public function discard(string $pushToken): void
    {
        $device = $this->ofPushToken($pushToken);

        if (null === $device) {
            return;
        }

        $this->getEntityManager()->remove($device);
        $this->getEntityManager()->flush();
    }

    /**
     * L'implémentation du port {@see PushTargets}. Une projection scalaire du jeton et
     * rien d'autre : le consommateur n'a besoin de connaître ni l'appareil ni sa
     * plateforme pour parler à Expo, et ce qui sort d'ici ne doit donner prise sur rien de
     * plus qu'un identifiant Expo.
     *
     * **Filtre sur l'environnement visé, pas sur l'environnement courant (#149).** Ce
     * filtre ne protège plus personne d'une campagne de prod — tout build EAS produit un
     * jeton `PRODUCTION`, voir le docblock de {@see DeviceEnvironment}. Ce qu'il fait
     * toujours : garder ce filtre en un seul endroit plutôt que de laisser chaque
     * consommateur du port s'en souvenir. `$targetEnvironment` est un réglage du
     * déploiement, pas une déduction de `%kernel.environment%`.
     *
     * **Filtre aussi sur la préférence du compte (#132).** Une catégorie coupée rend une
     * liste vide avant même d'interroger `UserDevice` : le joueur n'est **pas une cible**,
     * on ne filtre pas ses jetons à l'envoi. `find()` plutôt qu'une jointure dans la
     * requête ci-dessous parce que la préférence vit sur `User`, pas sur l'appareil — un
     * `Uuid` qui ne correspond à aucun compte tombe dans le même `null === $user` que la
     * préférence coupée, et rend la même liste vide.
     *
     * **Le log d'« aucune cible » vit ici, et nulle part ailleurs (#149).** C'est le seul
     * endroit qui sait laquelle des quatre causes a vidé la liste — un consommateur du
     * port ne voit qu'un tableau vide, et un warning posé chez lui crierait à chaque
     * catégorie coupée. Trois des quatre causes sont nominales (`info`) ; la quatrième —
     * des appareils existent, mais aucun dans l'environnement visé — est un `warning` :
     * c'est exactement le bug constaté sur un vrai iPhone qui a ouvert ce ticket.
     */
    public function of(Uuid $userId, NotificationCategory $category): array
    {
        $user = $this->getEntityManager()->find(User::class, $userId);

        if (null === $user) {
            $this->logger->warning('Aucune cible de push : compte introuvable.', [
                'userId' => $userId->toRfc4122(),
                'category' => $category->value,
            ]);

            return [];
        }

        if (!$user->notifiesOn($category)) {
            $this->logger->info('Aucune cible de push : catégorie coupée dans les préférences.', [
                'userId' => $userId->toRfc4122(),
                'category' => $category->value,
            ]);

            return [];
        }

        // Tous les appareils du joueur, pas seulement ceux de l'environnement visé :
        // distinguer « aucun appareil » de « des appareils, mais aucun dans
        // l'environnement visé » demande de voir les deux ensembles. Le volume est d'une
        // poignée de lignes par joueur, ça ne justifie pas une seconde requête.
        /** @var list<UserDevice> $devices */
        $devices = $this->createQueryBuilder('d')
            ->where('d.user = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getResult();

        if ([] === $devices) {
            $this->logger->info('Aucune cible de push : aucun appareil enregistré.', [
                'userId' => $userId->toRfc4122(),
                'category' => $category->value,
            ]);

            return [];
        }

        /** @var list<string> $tokens */
        $tokens = [];
        /** @var list<string> $foundEnvironments */
        $foundEnvironments = [];

        foreach ($devices as $device) {
            $foundEnvironments[] = $device->environment()->value;

            if ($this->targetEnvironment === $device->environment()) {
                $tokens[] = $device->pushToken();
            }
        }

        if ([] === $tokens) {
            $this->logger->warning('Aucune cible de push : des appareils existent, aucun dans l\'environnement visé.', [
                'userId' => $userId->toRfc4122(),
                'category' => $category->value,
                'targetEnvironment' => $this->targetEnvironment->value,
                'foundEnvironments' => array_values(array_unique($foundEnvironments)),
            ]);
        }

        return $tokens;
    }
}
