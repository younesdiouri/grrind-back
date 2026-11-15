<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\FounderCannotExcludeHimself;
use App\Community\Domain\Exception\PlayerIsNotAMember;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Security\GuildMutationAuthorization;
use App\Community\Infrastructure\Security\GuildVoter;

/**
 * Le fondateur exclut un membre.
 *
 * **Il ne peut pas s'exclure lui-même**, et le refus est prononcé avant toute écriture :
 * l'exclusion retire une ligne, point, alors que le départ sait transmettre la guilde ou
 * la dissoudre. S'autoriser à s'exclure laisserait une guilde sans fondateur, que plus
 * personne ne pourrait renommer, dissoudre ni ouvrir.
 *
 * **Un exclu peut revenir avec un code valide** : pas de liste noire en v1. Le recours du
 * fondateur est de révoquer le code (#116), ce qui referme la guilde pour tout le monde —
 * une liste noire demanderait une table, un écran pour la consulter et un autre pour en
 * sortir quelqu'un, alors que le vrai besoin est « je me suis trompé de personne ».
 *
 * Même verrou que le départ : exclure pendant qu'un joueur rejoint doit se sérialiser.
 */
final readonly class ExcludeMemberHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildMutationAuthorization $authorization,
    ) {
    }

    /**
     * @throws FounderCannotExcludeHimself
     * @throws PlayerIsNotAMember
     */
    public function __invoke(ExcludeMember $command): void
    {
        $this->guilds->transactional(function () use ($command): void {
            $guild = $this->authorization->lock($command->guild->id(), $command->actorId, GuildVoter::KICK);

            if ($command->actorId->equals($command->excludedId)) {
                throw new FounderCannotExcludeHimself();
            }

            // `part()` sait dissoudre une guilde vide, mais il ne peut pas y arriver ici :
            // le fondateur est encore là, puisqu'il vient d'être refusé à s'exclure.
            $guild->part($command->excludedId);

            $this->guilds->commit();
        });
    }
}
