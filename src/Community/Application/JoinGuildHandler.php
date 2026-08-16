<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildIsFull;
use App\Community\Domain\Exception\InviteCodeNotUsable;
use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildInviteCodeRepository;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Rejoindre une guilde avec un code.
 *
 * **L'ordre des vérifications est une décision, pas une commodité.** L'appartenance se
 * teste *avant* le code : un joueur qui a déjà une guilde reçoit le même 409 quel que soit
 * le code qu'il présente, donc il ne peut pas se servir de la route pour trier les codes
 * valides. L'inverse aurait fait de tout compte doté d'une guilde un oracle à codes.
 *
 * **Le plafond tient par le verrou, pas par le comptage.** Deux joueurs qui présentent le
 * même code au même instant pour la dernière place lisent tous les deux « il reste une
 * place » si rien ne les met en file — relire le `count()` juste avant l'`INSERT` déplace
 * la fenêtre sans la fermer. Le verrou sur la ligne de la guilde est pris *avant* que la
 * collection d'adhésions soit chargée, donc le comptage porte sur un état que personne ne
 * peut plus changer avant le `COMMIT`.
 *
 * L'index unique reste le dernier mot pour l'appartenance croisée : entre la lecture
 * d'adhésion et le `flush`, une seconde requête du **même joueur** vers une **autre**
 * guilde ne croise aucun verrou commun. Sa violation se traduit dans la même erreur.
 */
final readonly class JoinGuildHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildInviteCodeRepository $codes,
        private GuildMembershipRepository $memberships,
        private GuildRules $rules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyInAGuild
     * @throws InviteCodeNotUsable
     * @throws GuildIsFull
     */
    public function __invoke(JoinGuild $command): Guild
    {
        if (null !== $this->memberships->ofPlayer($command->playerId)) {
            throw new PlayerAlreadyInAGuild();
        }

        return $this->guilds->transactional(function () use ($command): Guild {
            $now = $this->clock->now();
            $invite = $this->codes->ofCode($command->code);

            // Inconnu, expiré, révoqué : une seule branche, une seule erreur. Les séparer
            // ferait de la route un oracle qui dit quels codes existent.
            if (null === $invite || !$invite->isUsableAt($now)) {
                throw new InviteCodeNotUsable();
            }

            // Le verrou d'abord, les adhésions ensuite — l'ordre inverse compterait des
            // lignes lues avant que la file ne se forme.
            $guild = $this->guilds->lockForUpdate($invite->guild()->id());

            if (null === $guild) {
                throw new InviteCodeNotUsable();
            }

            $guild->admit($command->playerId, $this->rules, $now);

            try {
                $this->guilds->commit();
            } catch (UniqueConstraintViolationException) {
                throw new PlayerAlreadyInAGuild();
            }

            return $guild;
        });
    }
}
