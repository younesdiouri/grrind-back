<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\User;
use App\Shared\Application\PlayerProfile;
use App\Shared\Application\PlayerProfiles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see PlayerProfiles} : c'est par cette classe, et uniquement
 * par elle, que `Community` voit le pseudo d'un joueur.
 *
 * **Une classe à part et non une méthode de plus sur `UserRepository`.** Le dépôt porte
 * déjà `PlayerTimezones::of(Uuid)`, et PHP ne connaît pas la surcharge : deux `of()` ne
 * cohabitent pas. La contrainte technique tombe bien, parce que la séparation est juste de
 * toute façon — le dépôt sert le firewall et l'écriture des comptes, pas la projection
 * publique d'un profil.
 *
 * **Une projection scalaire, jamais les entités.** Hydrater trente `User` pour en tirer
 * trente pseudos chargerait trente comptes complets — hash de mot de passe et adresse
 * compris — à un mètre d'une réponse HTTP. Ce qui sort d'ici ne peut pas donner prise sur
 * un compte parce qu'il n'y a rien d'autre dedans.
 */
final readonly class AccountPlayerProfiles implements PlayerProfiles
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<Uuid> $playerIds
     *
     * @return array<string, PlayerProfile>
     */
    public function of(array $playerIds): array
    {
        // `IN ()` n'est pas du SQL valide, et une guilde sans membre n'a rien à demander.
        if ([] === $playerIds) {
            return [];
        }

        /** @var list<array{id: Uuid, displayName: string, registeredAt: DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.id', 'u.displayName', 'u.registeredAt')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $playerIds))
            ->getQuery()
            ->getResult();

        $profiles = [];

        foreach ($rows as $row) {
            $profiles[$row['id']->toRfc4122()] = new PlayerProfile($row['displayName'], $row['registeredAt']);
        }

        return $profiles;
    }
}
