<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildInviteCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuildInviteCode>
 */
class GuildInviteCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildInviteCode::class);
    }

    public function add(GuildInviteCode $code): void
    {
        $this->getEntityManager()->persist($code);
    }

    /**
     * Le code vivant de la guilde, s'il y en a un. « Vivant » veut dire **non révoqué**,
     * pas « encore valable » : c'est exactement ce que porte l'index unique partiel, donc
     * la requête et la contrainte parlent de la même chose. Un code périmé mais non
     * révoqué est ce qu'on remplace quand on en génère un nouveau.
     */
    public function liveCodeOf(Guild $guild): ?GuildInviteCode
    {
        return $this->findOneBy(['guild' => $guild, 'revokedAt' => null]);
    }

    /**
     * La recherche par code, **sans aucun filtre d'état**. C'est délibéré : c'est
     * l'appelant qui décide si le code est utilisable, en une seule lecture
     * ({@see GuildInviteCode::isUsableAt()}), et qui rend la même erreur dans les trois
     * cas. Filtrer ici obligerait à distinguer « rien trouvé » de « trouvé mais mort », et
     * c'est précisément la distinction qu'on refuse de faire remonter.
     *
     * Le code est normalisé en amont : c'est le contrôleur qui met en capitales, parce que
     * c'est une question de saisie et pas de stockage.
     */
    public function ofCode(string $code): ?GuildInviteCode
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
