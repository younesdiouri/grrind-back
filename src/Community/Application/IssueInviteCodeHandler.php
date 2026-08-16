<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildInviteCode;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildInviteCodeRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Psr\Clock\ClockInterface;

/**
 * Génère le code d'invitation d'une guilde. **En générer un révoque le précédent** : il
 * n'y a jamais qu'un code vivant, et c'est ce qui rend « régénérer » utile — c'est le
 * geste par lequel le fondateur coupe un code qui a trop circulé.
 *
 * Le tout sous le verrou de la ligne de guilde. Deux générations simultanées liraient
 * chacune l'ancien code, le révoqueraient chacune, et insèreraient chacune le sien : la
 * seconde violerait l'index unique partiel et la requête échouerait en 500. Le verrou les
 * met en file, et l'index reste le filet.
 */
final readonly class IssueInviteCodeHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildInviteCodeRepository $codes,
        private GuildRules $rules,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IssueInviteCode $command): GuildInviteCode
    {
        return $this->guilds->transactional(function () use ($command): GuildInviteCode {
            $guild = $this->guilds->lockForUpdate($command->guild->id()) ?? $command->guild;
            $now = $this->clock->now();

            $live = $this->codes->liveCodeOf($guild);

            if (null !== $live) {
                $live->revoke($now);

                // **Deux `flush` et non un, dans la même transaction.** Doctrine ordonne
                // ses écritures par type — tous les `INSERT`, puis tous les `UPDATE` — donc
                // un `flush` unique insérerait le nouveau code avant d'avoir marqué
                // l'ancien révoqué, et l'index unique partiel refuserait l'insertion. La
                // transaction qui les enveloppe garde l'ensemble atomique : aucun état
                // intermédiaire n'est observable, et une panne entre les deux ne laisse pas
                // une guilde sans code.
                $this->codes->commit();
            }

            $code = GuildInviteCode::issueFor($guild, $this->rules->inviteCodeLifetime(), $now);
            $this->codes->add($code);
            $this->codes->commit();

            return $code;
        });
    }
}
