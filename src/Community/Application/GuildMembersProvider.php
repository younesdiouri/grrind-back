<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerProgression;
use App\Shared\Application\PlayerProgressions;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble la liste des membres : l'adhésion vient de `Community`, le pseudo d'`Identity`,
 * le niveau et le titre de `Progression`. **Aucun des deux derniers modules n'est nommé
 * ici** — seulement leurs ports.
 *
 * C'est le seul endroit du module qui traverse une frontière, et il le fait deux fois, en
 * deux appels, pour toute la liste. Le nombre de requêtes ne dépend donc pas du nombre de
 * membres : c'est ce que le test de comptage protège.
 *
 * **Un membre dont le compte est introuvable est écarté.** Le port ne rend pas de profil
 * pour lui, et il n'existe pas de pseudo neutre à afficher — contrairement à la
 * progression, où « niveau 1, aucun titre » se dessine parfaitement. Le cas est
 * aujourd'hui impossible (rien ne supprime un compte), mais la règle est la même que
 * partout ailleurs dans ce ticket : un profil incomplet ne fait pas rater l'écran entier.
 * Perdre une ligne vaut mieux que perdre la liste.
 */
final readonly class GuildMembersProvider
{
    public function __construct(
        private PlayerProfiles $profiles,
        private PlayerProgressions $progressions,
    ) {
    }

    /**
     * @return list<GuildMemberView>
     */
    public function of(Guild $guild): array
    {
        $members = $guild->members();
        $playerIds = array_map(static fn ($membership): Uuid => $membership->playerId(), $members);

        $profiles = $this->profiles->of($playerIds);
        $progressions = $this->progressions->of($playerIds);

        $views = [];

        foreach ($members as $membership) {
            $key = $membership->playerId()->toRfc4122();
            $profile = $profiles[$key] ?? null;

            if (null === $profile) {
                continue;
            }

            $views[] = new GuildMemberView(
                $membership->playerId(),
                $profile,
                // Le port garantit une entrée pour chaque identifiant demandé ; le repli
                // n'existe que pour que le type le dise.
                $progressions[$key] ?? PlayerProgression::untouched(),
                $membership->role(),
                $membership->joinedAt(),
            );
        }

        return $views;
    }
}
