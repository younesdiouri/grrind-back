<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Le modèle de héros qu'un joueur a choisi — purement cosmétique, aucun calcul ne le lit (#289).
 *
 * **Des modèles complets, pas des calques.** Chaque cas est une illustration finie, servie en
 * trois poses (`idle`, `attack`, `hit`), de dos pour le joueur lui-même et de face pour qui
 * l'affronte, en pleine taille et en miniature de 256 px.
 *
 * **Un enum et des fichiers statiques, pas une entrée du snapshot publié.** L'apparence ne
 * touche aucune valeur de jeu : elle n'a rien à faire dans le `rulesetVersion`, et le back-office
 * n'aurait qu'un formulaire de douze images à offrir pour un catalogue qui bouge à chaque
 * livraison d'illustrations. Les PNG vivent sous `public/appearances/`, Caddy les sert sans PHP.
 *
 * Le combat et Ālam figent **la clé**, pas les URL : un combat rejoué montre le modèle porté ce
 * jour-là, dans la version d'illustration courante. Une clé disparue du catalogue retombe sur
 * {@see self::default()} plutôt que de casser la lecture d'un vieux combat.
 */
enum Appearance: string
{
    case Murid = 'MURID';

    public static function default(): self
    {
        return self::Murid;
    }

    public static function fromSnapshot(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::default() : self::default();
    }

    /**
     * Le dossier sous `public/appearances/`. Il porte la version de l'illustration : remplacer
     * les PNG change l'URL, sinon les clients garderaient l'ancienne image en cache.
     *
     * `murid/v1` : silhouettes vectorielles provisoires, tirées de la planche de concept. Les
     * illustrations peintes, au pipeline d'Al-Kasal, arriveront en `murid/v2`.
     */
    public function directory(): string
    {
        return match ($this) {
            self::Murid => 'murid/v1',
        };
    }
}
