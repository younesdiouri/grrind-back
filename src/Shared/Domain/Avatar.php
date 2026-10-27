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
    // Le visage carré est retiré (#323) : le défaut passe au visage fin, anciens combats compris.
    public const array DEFAULT = ['version' => 1, 'body' => 'male', 'faceStyle' => 'original', 'noseStyle' => 'wide', 'hairStyle' => 'parted', 'skinTone' => 'tan', 'eyeColor' => 'brown', 'hairColor' => 'brown'];
    // Dans chaque champ, la première option compatible sert de repli au corps qui ne porte pas le défaut.
    public const array OPTIONS = [
        'body' => ['male', 'female'],
        'faceStyle' => ['original', 'angular', 'oval', 'heart', 'round'],
        'noseStyle' => ['original', 'wide', 'aquiline', 'flat', 'button', 'straight'],
        'hairStyle' => ['parted', 'buns', 'buzzed', 'mohawk', 'afro', 'long', 'ponytail', 'bob'],
        'skinTone' => ['light', 'tan', 'brown', 'deep'],
        'eyeColor' => ['brown', 'blue', 'green'],
        'hairColor' => ['brown', 'black', 'blond'],
    ];
    /** Options propres à un corps ; toute autre option va aux deux. */
    public const array BODIES = [
        'faceStyle' => ['angular' => ['male'], 'oval' => ['male'], 'heart' => ['female'], 'round' => ['female']],
        'noseStyle' => ['wide' => ['male'], 'aquiline' => ['male'], 'flat' => ['male'], 'button' => ['female'], 'straight' => ['female']],
        'hairStyle' => ['parted' => ['male'], 'mohawk' => ['male'], 'afro' => ['male'], 'buns' => ['female'], 'long' => ['female'], 'ponytail' => ['female'], 'bob' => ['female']],
    ];

    /** @return list<string> */
    public static function bodies(string $field, string $key): array
    {
        return self::BODIES[$field][$key] ?? self::OPTIONS['body'];
    }

    public static function fits(string $field, string $key, string $body): bool
    {
        return \in_array($body, self::bodies($field, $key), true);
    }

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
        foreach (array_keys(self::BODIES) as $field) {
            foreach ([(string) $avatar[$field], self::DEFAULT[$field], ...self::OPTIONS[$field]] as $key) {
                if (self::fits($field, $key, (string) $avatar['body'])) {
                    $avatar[$field] = $key;
                    break;
                }
            }
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
                'compatibleBodies' => self::bodies($field, $key),
            ], $keys);
        }

        return ['version' => self::VERSION, 'default' => self::DEFAULT, 'options' => $options,
            // Aucun item HEAD Unity publié depuis #315 ; le bandeau reste un prototype local.
            'headwear' => ['slot' => 'HEAD', 'options' => []]];
    }
}
