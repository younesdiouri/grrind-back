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
 * Level-up, ligue et rival viendront avec leurs propres tickets — ce n'en est pas un
 * catalogue à deviner à l'avance, seulement celui qu'un consommateur a déjà.
 *
 * **Les deux Risālāt sont séparées et non fondues en une catégorie `RISALA` (#194)**, parce
 * que les préférences de `/api/me` se coupent par catégorie : on peut vouloir faire taire le
 * bavardage de sa guilde sans faire taire ce qui nous demande d'agir. Les fondre obligerait à
 * choisir entre les deux, et le joueur qui coupe finirait par manquer son tour.
 *
 * **`SESSION_CREDITED` (#252) est un tombeau de compatibilité au #255.** Il reste accepté
 * pour les PATCH d'écrans déjà chargés et les messages en vol, mais ne figure plus dans le
 * GET et aucun push serveur ne l'utilise avant sa suppression au #256.
 */
enum NotificationCategory: string
{
    case GuildActivity = 'GUILD_ACTIVITY';

    /** C'est ton tour : choisis la Risāla de la semaine avant l'échéance. À une seule personne. */
    case RisalaTurn = 'RISALA_TURN';

    /** La Risāla de la semaine est partie. À toute la guilde, au même instant. */
    case RisalaRevealed = 'RISALA_REVEALED';

    /** Ta séance a été créditée pendant que l'app était en fond — le réveil de la dopamine que le `RewardSummary`, non regardé, ne déclenche pas seul. */
    case SessionCredited = 'SESSION_CREDITED';
}
