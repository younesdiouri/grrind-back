<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/** Famille d'animation uniquement : direct/uppercut ou taille/estoc, sans effet sur les dégâts. */
enum WeaponType: string
{
    case Fist = 'FIST';
    case Sword = 'SWORD';
}
