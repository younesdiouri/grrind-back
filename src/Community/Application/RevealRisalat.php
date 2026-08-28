<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Le battement hebdomadaire des Risālāt : sceller le tour échu, en tirer un nouveau.
 *
 * **Il ne porte rien, et c'est le point.** Ce message n'annonce pas « on est dimanche 20h » ;
 * il dit « regarde si quelque chose est dû ». C'est la différence entre une bascule qui
 * dépend d'un déclencheur exact et une bascule qui se répare toute seule.
 *
 * Le planificateur ({@see \App\Community\Infrastructure\Scheduler\RisalaSchedule}) le publie
 * **toutes les heures**, pas une fois par semaine, et cette décision-là mérite d'être écrite
 * parce qu'elle a l'air d'un gaspillage :
 *
 * - la vérité est l'échéance stockée sur le tour, jamais l'instant où ce message arrive.
 *   {@see RevealRisalatHandler} ne scelle que ce qui est échu, donc un battement qui tombe à
 *   un moment quelconque ne peut rien casser ;
 * - un déclencheur hebdomadaire, lui, n'a qu'une occasion par semaine. Le manquer coûte une
 *   semaine entière à toutes les guildes — et il se manque pour de vrai : le `stateful` du
 *   composant Scheduler garde son point de reprise dans le cache applicatif, qui est un
 *   cache **fichier** (`config/packages/cache.yaml`) sur une machine Fly dont le disque
 *   disparaît à chaque déploiement. Un déploiement du dimanche après-midi suffirait ;
 * - au réveil d'un worker arrêté, le rattrapage se fait tout seul en moins d'une heure, sans
 *   point de reprise à conserver ni rejeu de plusieurs rendez-vous manqués à arbitrer.
 *
 * Le coût réel est une requête par heure qui ne trouve rien à faire, et
 * {@see \App\Community\Infrastructure\Doctrine\RisalaRepository::guildsToAdvance()} est
 * écrite pour que ce soit exactement une requête.
 */
final readonly class RevealRisalat
{
}
