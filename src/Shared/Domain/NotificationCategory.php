<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Le catalogue fermé des types de notification GRRIND — resté une simple chaîne dans
 * {@see \App\Shared\Application\PushNotification} depuis le #130, faute d'un premier
 * consommateur pour trancher les valeurs. Le #132 en apporte un : les préférences par
 * catégorie de `/api/me` ont besoin d'une énumération, pas d'une chaîne libre que le
 * client pourrait mal orthographier des deux côtés du contrat.
 *
 * **Ici plutôt que `Shared/Application`, malgré ce que suggérait le ticket.** C'est
 * {@see \App\Identity\Domain\User} qui porte les préférences coupées — le compte, pas
 * l'appareil — et un module ne fait remonter du framework applicatif dans son Domain
 * pour aucune autre énumération fermée du projet (voir {@see Activity\Discipline}
 * ou {@see Modifier\ModifierType}, tous deux `Shared/Domain`). La
 * mettre ici garde `User` dans son propre layer et laisse `PushNotification`
 * (`Shared/Application`) en dépendre dans le sens habituel — Application vers Domain,
 * jamais l'inverse.
 *
 * Une seule valeur pour l'instant : `GUILD_ACTIVITY`, un co-équipier qui s'est entraîné.
 * Level-up, ligue et rival viendront avec leurs propres tickets — ce n'en est pas un
 * catalogue à deviner à l'avance, seulement celui qu'un consommateur a déjà.
 */
enum NotificationCategory: string
{
    case GuildActivity = 'GUILD_ACTIVITY';
}
