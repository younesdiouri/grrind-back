<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Infrastructure\Doctrine\GuildRepository;

/**
 * Dissoudre une guilde : elle et toutes ses adhésions partent dans la **même**
 * transaction.
 *
 * Ce n'est pas une précaution de style. L'index unique porte sur le joueur : une adhésion
 * qui survivrait à sa guilde enfermerait son porteur hors de toute guilde — il ne pourrait
 * ni en fonder ni en rejoindre une autre, et rien dans l'API ne lui dirait pourquoi. Le
 * `cascade: remove` de l'association et le `ON DELETE CASCADE` de la colonne disent la
 * même chose deux fois, à l'ORM et à la base ; la transaction est ce qui garantit qu'aucun
 * état intermédiaire n'est observable.
 */
final readonly class DissolveGuildHandler
{
    public function __construct(private GuildRepository $guilds)
    {
    }

    public function __invoke(DissolveGuild $command): void
    {
        $this->guilds->transactional(function () use ($command): void {
            $this->guilds->dissolve($command->guild);
            $this->guilds->commit();
        });
    }
}
