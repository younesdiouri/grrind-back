<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Le système d'exploitation qui a émis le jeton de push. Deux cas aujourd'hui, comme
 * {@see \App\Shared\Domain\Activity\WorkoutSource} n'en avait que deux au premier jour :
 * l'enum accueillera Health Connect / Android Wear ou une plateforme de bureau le jour où
 * Grrind en aura besoin, sans que la forme de `UserDevice` ait à bouger.
 *
 * Ce n'est **pas** le fournisseur santé (`WorkoutSource`) : un même joueur a une seule
 * plateforme d'appareil et potentiellement deux sources santé s'il change de montre.
 */
enum DevicePlatform: string
{
    case Ios = 'IOS';
    case Android = 'ANDROID';
}
