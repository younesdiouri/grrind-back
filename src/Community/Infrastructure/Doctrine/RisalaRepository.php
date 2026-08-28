<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Risala>
 */
class RisalaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Risala::class);
    }

    public function add(Risala $risala): void
    {
        $this->getEntityManager()->persist($risala);
    }

    /**
     * Le tour ouvert d'une guilde, s'il y en a un. L'index unique partiel garantit qu'il n'y
     * en a jamais deux — voir le docblock de {@see Risala}.
     */
    public function openTurnOf(Guild $guild): ?Risala
    {
        return $this->findOneBy(['guild' => $guild, 'status' => RisalaStatus::Drawn]);
    }

    /**
     * Les Risālāt vivantes d'une guilde à un instant, dans l'ordre où elles ont été
     * révélées. Il y en a deux en régime établi, une seule après un tour manqué.
     *
     * **La vie se lit sur les dates, jamais sur un statut d'expiration** : une bascule
     * hebdomadaire manquée ne peut donc pas laisser une Risāla morte continuer de bonifier
     * des séances. C'est ce qui permet à cette requête d'être la seule vérité, aussi bien
     * pour l'écran que pour le moteur d'XP (#192).
     *
     * @return list<Risala>
     */
    public function liveIn(Guild $guild, DateTimeImmutable $at): array
    {
        /** @var list<Risala> $live */
        $live = $this->createQueryBuilder('r')
            ->where('r.guild = :guild')
            ->andWhere('r.status = :sent')
            ->andWhere('r.revealedAt <= :at')
            ->andWhere('r.expiresAt > :at')
            ->orderBy('r.revealedAt', 'ASC')
            ->setParameter('guild', $guild)
            ->setParameter('sent', RisalaStatus::Sent)
            ->setParameter('at', $at)
            ->getQuery()
            ->getResult();

        return $live;
    }

    /**
     * Le cycle de rotation en cours dans cette guilde, et qui y a déjà envoyé sa Risāla.
     *
     * Les deux d'un coup parce qu'ils ne se lisent pas séparément : « qui a déjà envoyé »
     * n'a de sens que rapporté à un cycle, et deux requêtes laisseraient la place à une
     * réponse composée de deux états différents.
     *
     * Une guilde sans aucune Risāla rend le cycle `0` et personne — c'est l'état d'une
     * guilde qui vient d'être fondée, pas une anomalie.
     *
     * @return array{cycle: int, senders: list<Uuid>}
     */
    public function currentCycleOf(Guild $guild): array
    {
        $cycle = $this->createQueryBuilder('r')
            ->select('MAX(r.cycle)')
            ->where('r.guild = :guild')
            ->setParameter('guild', $guild)
            ->getQuery()
            ->getSingleScalarResult();

        $cycle = null === $cycle ? 0 : (int) $cycle;

        /** @var list<array{senderId: Uuid}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.senderId')
            ->where('r.guild = :guild')
            ->andWhere('r.cycle = :cycle')
            ->setParameter('guild', $guild)
            ->setParameter('cycle', $cycle)
            ->getQuery()
            ->getResult();

        return ['cycle' => $cycle, 'senders' => array_map(static fn (array $row): Uuid => $row['senderId'], $rows)];
    }

    /**
     * Les guildes que la bascule doit examiner : celles dont le tour est échu, et celles qui
     * n'en ont aucun.
     *
     * **En SQL et non en DQL** : la question porte sur l'*absence* d'une ligne liée, ce que
     * DQL ne sait pas dire sans une sous-requête corrélée que Doctrine rend illisible. Et
     * surtout, elle ne charge rien — la bascule tourne toutes les heures et n'a besoin que
     * d'identifiants, sur lesquels elle prendra ses verrous un par un.
     *
     * Une guilde d'un seul membre est rendue comme les autres : c'est
     * {@see \App\Community\Application\RevealRisalatHandler} qui décide de ne pas y tirer,
     * après avoir pris son verrou — le nombre de membres lu ici serait déjà périmé.
     *
     * @return list<Uuid>
     */
    public function guildsToAdvance(DateTimeImmutable $at): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT g.id
               FROM community_guild g
               LEFT JOIN community_risala r ON r.guild_id = g.id AND r.status = :drawn
              WHERE r.id IS NULL OR r.deadline <= :at
              ORDER BY g.id',
            ['drawn' => RisalaStatus::Drawn->value, 'at' => $at],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );

        return array_map(static fn (array $row): Uuid => Uuid::fromString($row['id']), $rows);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
