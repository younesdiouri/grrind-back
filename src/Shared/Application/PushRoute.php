<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\PushRouteType;
use Symfony\Component\Uid\Uuid;

/**
 * « Où ce push mène-t-il » (#144) — distinct de `groupingKey` sur {@see PushNotification},
 * qui répond à « quelle notification celle-ci remplace-t-elle ». Les deux voyagent côte à
 * côte dans `data`, jamais l'un à la place de l'autre : {@see PushRouteType} dit *quel*
 * écran ouvrir, `targetId` dit *lequel* de ses éléments — pour `PLAYER_PROFILE`,
 * l'identifiant que `GET /api/players/{id}` sait résoudre.
 *
 * **Jamais de donnée de jeu ici.** `targetId` est une clé de ressource à relire, pas une
 * valeur à afficher : le texte déjà écrit dans `title`/`body` assume d'être daté, mais une
 * notification peut dormir des heures avant d'être touchée, et ce que le tap affiche
 * ensuite doit rester exact — donc relu depuis l'API, jamais transporté.
 */
final readonly class PushRoute
{
    public function __construct(
        public PushRouteType $type,
        public Uuid $targetId,
    ) {
    }
}
