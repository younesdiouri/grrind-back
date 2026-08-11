<?php

declare(strict_types=1);

namespace App\Shared\Domain\Modifier;

/**
 * Qui produit un modificateur. Sert au joueur avant de servir au calcul : c'est ce qui
 * transforme « +18 XP » en « +18 grâce à ta série », et donc un nombre en compréhension.
 *
 * Quatre sources, une par module contributeur — `Progression` pour les compétences,
 * `Rewards` pour les objets, `Engagement` pour le streak et la ligue. Aucune ne se
 * confond avec le socle ni avec un garde-fou : ceux-là ne sont pas des modificateurs, ils
 * ne passent pas par le resolver, et ils ont leur propre place dans le breakdown.
 */
enum ModifierSource: string
{
    case Streak = 'STREAK';
    case Skill = 'SKILL';
    case Item = 'ITEM';
    case League = 'LEAGUE';
}
