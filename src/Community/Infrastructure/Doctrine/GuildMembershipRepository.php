<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\GuildMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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

    /**
     * Ces deux joueurs sont-ils dans la même guilde ?
     *
     * Une auto-jointure et un booléen, plutôt que deux lectures suivies d'une comparaison :
     * la question posée par {@see \App\Community\Infrastructure\Security\PlayerVoter} est
     * binaire, et lui rendre deux adhésions l'obligerait à refaire le rapprochement — donc
     * lui donnerait l'occasion de le faire de travers. Ce qui sort d'ici ne permet pas de
     * conclure autre chose que ce qui a été demandé.
     *
     * Aucun contrôle de « c'est moi-même » ici : deux fois le même joueur rendrait `true`,
     * ce qui est vrai mais n'est pas la question. Le voter traite ce cas avant d'appeler.
     */
    public function shareAGuild(Uuid $left, Uuid $right): bool
    {
        $shared = $this->createQueryBuilder('m')
            ->select('1')
            ->join(GuildMembership::class, 'other', Join::WITH, 'other.guild = m.guild')
            ->where('m.playerId = :left')
            ->andWhere('other.playerId = :right')
            ->setParameter('left', $left, UuidType::NAME)
            ->setParameter('right', $right, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $shared;
    }
}
