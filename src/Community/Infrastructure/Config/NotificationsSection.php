<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Config;

use App\Shared\Infrastructure\Config\GameBalanceSection;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Le schéma de `config/game/v1/notifications.yaml` — les garde-fous qui empêchent une
 * séance créditée de noyer une guilde de pushes (#133).
 *
 * Pas de délégation à un objet du domaine, contrairement à `CommunitySection` : les cinq
 * réglages n'ont aucune cohérence à faire respecter entre eux — chaque combinaison
 * d'heures calmes valides est une combinaison valide, et `announcement_delay_seconds` ne
 * contraint rien d'autre qu'un minimum — donc les bornes du `TreeBuilder` suffisent, même
 * geste que `XpSection`.
 *
 * **`stale_window_minutes` (#134) n'a aucune cohérence à faire respecter avec
 * `announcement_delay_seconds` non plus** — voir le docblock du fichier YAML pour
 * pourquoi la valeur est un multiple franc choisi à la main plutôt qu'une expression des
 * deux : le composant Config n'a pas de multiplication à offrir ici.
 *
 * **Le plafond par destinataire n'y est pas**, malgré ce que suggérait le ticket, qui
 * range les quatre réglages au même endroit. Il vit dans
 * `config/packages/rate_limiter.yaml`, sous le limiteur `guild_activity_push` — à côté de
 * `guild_join`, un garde-fou tout aussi « game design » que ceux d'ici. Deux raisons : le
 * composant Config valide le `integerNode` d'un `TreeBuilder` *avant* que les compiler
 * passes tournent, donc avant que `%game.notifications.*%` existe — un plafond sourcé
 * d'ici casserait la compilation plutôt que de la nourrir ; et `symfony/rate-limiter`
 * attend une policy déclarée sous `framework.rate_limiter`, pas un couple de paramètres
 * qu'un service irait consommer lui-même. Suivre la convention déjà posée par
 * `guild_join` coûte moins qu'en inventer une seconde pour un seul réglage.
 */
final class NotificationsSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'notifications.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('notifications');

        $tree->getRootNode()
            ->children()
                ->integerNode('freshness_window_minutes')->isRequired()->min(1)->end()
                ->integerNode('announcement_delay_seconds')->isRequired()->min(1)->end()
                ->integerNode('stale_window_minutes')->isRequired()->min(1)->end()
                ->integerNode('quiet_hours_start_hour')->isRequired()->min(0)->max(23)->end()
                ->integerNode('quiet_hours_end_hour')->isRequired()->min(0)->max(23)->end()
            ->end()
        ;

        return $tree;
    }
}
