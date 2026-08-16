<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Infrastructure\Doctrine\GuildInviteCodeRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Psr\Clock\ClockInterface;

/**
 * Coupe le code sans en ouvrir un autre. C'est le recours du fondateur quand un code a
 * fui : la guilde redevient close, et plus personne n'entre tant qu'il n'en génère pas un
 * nouveau.
 *
 * **Révoquer alors qu'il n'y a rien à révoquer n'est pas une erreur.** L'état visé est
 * « aucun code vivant », et il est déjà atteint : rendre 404 obligerait le client à
 * distinguer deux situations qui, pour le joueur, sont la même.
 *
 * Sous le même verrou que la génération, pour que révoquer et régénérer ne se croisent pas.
 */
final readonly class RevokeInviteCodeHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildInviteCodeRepository $codes,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeInviteCode $command): void
    {
        $this->guilds->transactional(function () use ($command): void {
            $guild = $this->guilds->lockForUpdate($command->guild->id()) ?? $command->guild;

            $this->codes->liveCodeOf($guild)?->revoke($this->clock->now());
            $this->codes->commit();
        });
    }
}
