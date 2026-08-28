<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildMembership;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRotation;
use App\Community\Domain\RisalaRules;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * La bascule d'une semaine à l'autre, guilde par guilde : sceller le tour échu, en tirer un
 * nouveau. C'est le seul endroit du module qui fait avancer la rotation.
 *
 * ## Une transaction par guilde, et un verrou dedans
 *
 * Le verrou est celui que prend déjà l'arrivée dans une guilde
 * ({@see GuildRepository::lockForUpdate()}), et pour la même raison : la composition de la
 * guilde décide du vivier, et un membre qui entre pendant le tirage rendrait le rang tiré
 * faux. Le verrou porte sur une ligne, donc deux guildes ne s'attendent jamais.
 *
 * **Une guilde qui échoue ne corrompt pas les autres, mais elle interrompt la boucle** —
 * l'`EntityManager` se ferme sur une transaction avortée, il n'y a pas de « continuer avec
 * la suivante » honnête. Ce n'est pas un problème : le message repart en réessai, les
 * guildes déjà avancées n'ont plus rien à faire, et celles qui restent sont traitées à ce
 * moment-là. C'est exactement ce que l'idempotence ci-dessous achète.
 *
 * ## Idempotent par construction, sans clé ni table
 *
 * Rejouer ce message ne produit rien de plus : un tour qui vient d'être tiré a son échéance
 * dans le futur, donc `isDueAt()` est faux, donc la méthode sort avant de toucher à quoi que
 * ce soit. C'est ce qui remplace le verrou global qu'un planificateur multi-worker
 * demanderait — et c'est plus solide, parce qu'un verrou protégerait un handler dont on
 * n'aurait jamais vérifié qu'il supporte d'être rejoué.
 */
final readonly class RevealRisalatHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private RisalaRepository $risalat,
        private RisalaRules $rules,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RevealRisalat $message): void
    {
        $now = $this->clock->now();

        // Un seul instant pour toute la bascule, relu nulle part : deux guildes traitées à
        // une seconde d'écart doivent recevoir la même semaine, sans quoi une échéance
        // pourrait tomber entre les deux.
        foreach ($this->risalat->guildsToAdvance($now) as $guildId) {
            $this->entityManager->wrapInTransaction(fn () => $this->advance($guildId, $now));
        }
    }

    private function advance(Uuid $guildId, DateTimeImmutable $now): void
    {
        $guild = $this->guilds->lockForUpdate($guildId);

        // Dissoute entre la lecture des identifiants et la prise du verrou. Rien à faire, et
        // surtout pas une erreur : la bascule court après l'état du monde, elle ne le fige pas.
        if (null === $guild) {
            return;
        }

        $open = $this->risalat->openTurnOf($guild);

        if (null !== $open) {
            if (!$open->isDueAt($now)) {
                return;
            }

            // Le second refus du scellement : celui qui a quitté la guilde n'envoie plus
            // rien, même s'il avait choisi. Voir {@see Risala::seal()}.
            $open->seal($this->rules, $guild->hasMember($open->senderId()));

            // **Deux `flush()`, et le premier n'est pas décoratif.** Doctrine écrit les
            // insertions avant les mises à jour dans un même `flush()` : le tour neuf
            // partirait donc en base pendant que l'ancien y est encore `DRAWN`, et l'index
            // unique partiel refuserait la ligne. C'est lui qui a trouvé le bug, ce qui est
            // exactement ce qu'on lui demande — un `if` dans ce fichier ne l'aurait jamais vu.
            $this->risalat->commit();
        }

        $this->drawNextTurn($guild, $now);
        $this->risalat->commit();
    }

    /**
     * Ouvre le tour de la semaine à venir. L'échéance vient de la grille — le prochain
     * rendez-vous **strictement après maintenant** — et non de l'échéance qu'on vient de
     * sceller : un worker resté arrêté plus d'une semaine ne doit pas rattraper les
     * rendez-vous perdus en enchaînant les tours à l'heure, il doit repartir de la semaine
     * en cours. Le prix est une rotation en retard d'un cran, ce qui ne se voit pas ; le
     * rattrapage, lui, brûlerait un cycle entier en une matinée.
     */
    private function drawNextTurn(Guild $guild, DateTimeImmutable $now): void
    {
        $members = array_map(static fn (GuildMembership $membership): Uuid => $membership->playerId(), $guild->members());

        // Un défi qu'on s'envoie à soi-même n'est pas un défi. La guilde rejoint la rotation
        // à la bascule qui suit l'arrivée de son deuxième membre.
        if (\count($members) < 2) {
            return;
        }

        $cycle = $this->risalat->currentCycleOf($guild);

        $rotation = new RisalaRotation($members, $cycle['senders'], $cycle['cycle']);

        // `random_int` ici plutôt qu'un port injecté : ce qui mérite d'être testé est la
        // *règle* — qui est éligible, quand le cycle repart — et elle vit entièrement dans
        // `RisalaRotation`, qui reçoit le rang au lieu de le tirer. Ce qui reste ici est un
        // tirage uniforme sur un vivier déjà constitué, et la trace écrite sur la ligne
        // (`drawRoll`, `drawPoolSize`) suffit à le rejouer.
        $roll = random_int(0, \count($rotation->pool) - 1);

        $this->risalat->add(Risala::draw($guild, $rotation, $roll, $now, $this->rules->nextRevealAfter($now)));
    }
}
