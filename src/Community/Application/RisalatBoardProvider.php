<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Guild;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRules;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble l'écran des Risālāt : les vivantes, le tour en cours, et les pseudos qui vont
 * avec.
 *
 * **Un seul appel au port des profils pour tout l'écran**, comme {@see GuildMembersProvider}
 * pour la liste des membres : il y a au plus trois personnes à nommer, mais le contrat batch
 * existe précisément pour qu'on ne puisse pas écrire la boucle.
 *
 * **Pas de guilde, pas d'écran.** L'appelant reçoit un 404, contrairement à
 * `GET /api/guilds/mine` qui rend `{"guild": null}` : là-bas, c'est la requête d'ouverture de
 * l'onglet et « je n'ai pas de guilde » est une réponse que l'écran sait dessiner. Ici,
 * l'écran n'existe qu'à l'intérieur d'une guilde.
 */
final readonly class RisalatBoardProvider
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private RisalaRepository $risalat,
        private PlayerProfiles $profiles,
        private CreditingDisciplines $crediting,
        private RisalaRules $rules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws GuildNotFound
     */
    public function of(Uuid $playerId): RisalatBoard
    {
        $guild = $this->guildOf($playerId);
        $now = $this->clock->now();

        $live = $this->risalat->liveIn($guild, $now);
        $turn = $this->risalat->openTurnOf($guild);

        $senderIds = array_map(static fn (Risala $risala): Uuid => $risala->senderId(), $live);

        if (null !== $turn) {
            $senderIds[] = $turn->senderId();
        }

        $profiles = $this->profiles->of(array_values($senderIds));

        return new RisalatBoard(
            array_map(
                fn (Risala $risala): RisalaView => new RisalaView(
                    $risala->id(),
                    $risala->discipline() ?? throw new LogicException(\sprintf('Risāla %s révélée sans discipline.', $risala->id())),
                    $risala->senderId(),
                    $profiles[$risala->senderId()->toRfc4122()] ?? null,
                    // Une Risāla vivante a forcément été révélée et expire : c'est la
                    // définition même de « vivante », que le dépôt applique en SQL.
                    $risala->revealedAt() ?? throw new LogicException('Risāla vivante sans date de révélation.'),
                    $risala->expiresAt() ?? throw new LogicException('Risāla vivante sans date d\'expiration.'),
                    $risala->senderId()->equals($playerId) ? $this->rules->senderBonusPercent() : $this->rules->recipientBonusPercent(),
                ),
                $live,
            ),
            null === $turn ? null : new RisalaTurnView(
                $turn->senderId(),
                $profiles[$turn->senderId()->toRfc4122()] ?? null,
                $mine = $turn->senderId()->equals($playerId),
                $turn->deadline(),
                // Le choix reste secret jusqu'à la révélation : c'est ce qui garantit qu'il a
                // été fait à l'aveugle, et l'annoncer d'avance viderait le rendez-vous du
                // dimanche soir de sa raison d'être.
                $mine ? $turn->discipline() : null,
                $this->choosableIn($live),
            ),
            // Depuis l'instant de la requête, pas de la dernière bascule enregistrée : une
            // bascule ratée ou en retard laisserait sinon `deadline` déjà passée, et le client
            // afficherait un rendez-vous échu (#202).
            $this->rules->nextRevealAfter($now),
        );
    }

    /**
     * @throws GuildNotFound
     */
    public function guildOf(Uuid $playerId): Guild
    {
        return $this->memberships->ofPlayer($playerId)?->guild() ?? throw new GuildNotFound();
    }

    /**
     * Les disciplines qu'un tour peut encore demander : celles qui créditent, moins celles
     * déjà portées par une Risāla vivante.
     *
     * Calculée ici et rendue au client plutôt que recalculée là-bas : la même liste sert à
     * valider le choix, et deux formulations de la même règle finissent toujours par diverger.
     *
     * @param list<Risala> $live
     *
     * @return list<Discipline>
     */
    public function choosableIn(array $live): array
    {
        $challenged = array_map(static fn (Risala $risala): ?Discipline => $risala->discipline(), $live);

        return array_values(array_filter(
            $this->crediting->all(),
            static fn (Discipline $discipline): bool => !\in_array($discipline, $challenged, true),
        ));
    }
}
