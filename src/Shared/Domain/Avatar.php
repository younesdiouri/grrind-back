<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Catalogue cosmétique Unity/Quaternius (#308), indépendant du ruleset de jeu.
 * Les identifiants du bridge sont stables dans une version. Les vêtements et coiffes
 * viennent exclusivement de l'équipement serveur, jamais de ces choix gratuits.
 * Le défaut V1 reste figé pour les anciens combats sans avatar : aucun profil n'est relu.
 */
final class Avatar
{
    public const int VERSION = 1;
    public const array DEFAULT = ['version' => 1, 'body' => 'male', 'faceStyle' => 'square', 'noseStyle' => 'wide', 'hairStyle' => 'parted', 'skinTone' => 'tan', 'eyeColor' => 'brown', 'hairColor' => 'brown'];
    public const array OPTIONS = [
        'body' => ['male', 'female'],
        'faceStyle' => ['original', 'square'],
        'noseStyle' => ['original', 'wide'],
        'hairStyle' => ['parted', 'buzzed', 'buns', 'long'],
        'skinTone' => ['light', 'tan', 'brown', 'deep'],
        'eyeColor' => ['brown', 'blue', 'green'],
        'hairColor' => ['brown', 'black', 'blond'],
    ];

    /**
     * Repli de lecture uniquement : l'API refuse les choix inconnus avant toute écriture.
     * Une option retirée revient au défaut de son champ, sans réécrire le fait stocké.
     *
     * @return array<string, int|string>
     */
    public static function resolve(mixed $value): array
    {
        $avatar = self::DEFAULT;
        if (!\is_array($value) || self::VERSION !== ($value['version'] ?? null)) {
            return $avatar;
        }
        foreach (self::OPTIONS as $field => $options) {
            if (\in_array($value[$field] ?? null, $options, true)) {
                $avatar[$field] = $value[$field];
            }
        }
        if ('female' === $avatar['body']) {
            $avatar['faceStyle'] = 'original';
            $avatar['noseStyle'] = 'original';
        }

        return $avatar;
    }

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        $options = [];
        foreach (self::OPTIONS as $field => $keys) {
            $options[$field] = array_map(static fn (string $key): array => [
                'key' => $key,
                'compatibleBodies' => \in_array($key, ['square', 'wide'], true) ? ['male'] : self::OPTIONS['body'],
            ], $keys);
        }

        return ['version' => self::VERSION, 'default' => self::DEFAULT, 'options' => $options,
            // Aucun item HEAD Unity publié depuis #315 ; le bandeau reste un prototype local.
            'headwear' => ['slot' => 'HEAD', 'options' => []]];
    }
}
