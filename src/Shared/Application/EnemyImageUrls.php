<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les trois poses publiées d'un ennemi, depuis l'entrée `image_paths` d'un snapshot de règles.
 *
 * Partagé parce que deux modules les lisent sans se connaître : `Combat` dans le snapshot
 * courant, `Community` dans celui qu'une édition d'Ālam a figé (#289).
 */
final class EnemyImageUrls
{
    /** @return array{idle: string, attack: string, hit: string}|null un pack incomplet n'est pas un pack */
    public static function of(mixed $paths, UrlGeneratorInterface $urls): ?array
    {
        if (!\is_array($paths)) {
            return null;
        }
        $result = [];
        foreach (['idle', 'attack', 'hit'] as $pose) {
            $path = $paths[$pose] ?? null;
            if (!\is_string($path) || '' === $path || 'placeholder.png' === $path) {
                return null;
            }
            $result[$pose] = $urls->generate('game_image', ['name' => $path], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $result;
    }
}
