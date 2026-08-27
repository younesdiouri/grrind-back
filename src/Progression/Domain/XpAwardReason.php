<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Pourquoi un `RewardSummary` peut afficher 0 XP **sans que ce soit une punition**.
 *
 * `XpBreakdown` refuse d'être vide — un montant sans explication n'est pas un montant —
 * mais une discipline qui ne crédite pas d'XP (#167, `XpRates::credits()`) n'a justement
 * aucune ligne à produire : ni socle, ni rendements décroissants, ni plafond, puisque
 * `LedgerSessionRewards` s'arrête avant `XpCalculator`. Un breakdown vide serait alors
 * une séance non expliquée, et une ligne « base : 0 » mentirait sur ce qui a été calculé
 * — rien ne l'a été. `reason` porte l'explication à la place, dans `Shared\Application\
 * SessionReward::$reason`, et le client la rend en une phrase.
 *
 * Fermé et exposé au contrat client (`RewardSummary.xp.reason`) : ajouter un cas est un
 * changement d'API, pas une reformulation.
 */
enum XpAwardReason: string
{
    /** La marche : elle n'alimente que Vitality, jamais l'XP — voir le docblock de `XpRates`. */
    case NoXpFeedsVitality = 'NO_XP_FEEDS_VITALITY';
}
