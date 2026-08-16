<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\GuildMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<GuildMembership>
 */
class GuildMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildMembership::class);
    }

    /**
     * L'adhésion du joueur, s'il en a une. C'est le point d'entrée de tout ce qui part du
     * joueur plutôt que de la guilde : « ai-je une guilde », « puis-je en fonder une »,
     * « quelle guilde dois-je afficher ».
     *
     * Rend une adhésion et non une guilde : l'appelant a presque toujours besoin du rôle
     * dans la foulée, et le relire coûterait une seconde requête.
     */
    public function ofPlayer(Uuid $playerId): ?GuildMembership
    {
        return $this->findOneBy(['playerId' => $playerId]);
    }
}
