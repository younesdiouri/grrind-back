<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit `config/game/v1/`, valide chaque fichier contre son schéma, et en tire le
 * `rulesetVersion`.
 *
 * Ne dépend que du système de fichiers : il tourne à la compilation du conteneur
 * ({@see GameBalancePass}) et se teste sans conteneur du tout.
 */
final readonly class GameBalanceLoader
{
    public function __construct(private string $directory)
    {
    }

    /**
     * @throws InvalidConfigurationException un fichier manque, est en trop, ou son contenu ne tient pas le schéma
     */
    public function load(GameBalanceSection ...$sections): GameBalance
    {
        $processor = new Processor();
        $values = [];

        foreach ($sections as $section) {
            $path = $this->directory.'/'.$section->file();

            if (!is_file($path)) {
                throw new InvalidConfigurationException(\sprintf('Fichier d\'équilibrage absent : "%s".', $path));
            }

            $parsed = Yaml::parseFile($path);

            // Un fichier vide vaut « aucune clé fournie » et non « pas de section » :
            // c'est le schéma qui dira si les clés manquantes étaient obligatoires.
            $parsed ??= [];

            if (!\is_array($parsed)) {
                throw new InvalidConfigurationException(\sprintf('"%s" doit contenir une table de réglages, pas une valeur seule.', $path));
            }

            /** @var array<string, mixed> $processed le composant Config rend un tableau de clés, garni des défauts du schéma */
            $processed = $processor->processConfiguration($section, [$parsed]);

            $values[basename($section->file(), '.yaml')] = $processed;
        }

        $this->refuseStrayFiles(...$sections);

        ksort($values);

        return new GameBalance($values, $this->version($values));
    }

    /**
     * Un YAML posé dans le dossier sans schéma est du réglage qui ne s'applique pas :
     * personne ne le lit, rien ne le valide, et il n'entre pas dans le `rulesetVersion`.
     * Le silence serait le pire des cas — on préfère casser la compilation le jour où le
     * fichier apparaît plutôt que de chercher six mois plus tard pourquoi un rééquilibrage
     * n'a rien changé.
     */
    private function refuseStrayFiles(GameBalanceSection ...$sections): void
    {
        $declared = array_map(static fn (GameBalanceSection $section): string => $section->file(), $sections);
        $found = array_map('basename', glob($this->directory.'/*.yaml') ?: []);
        $stray = array_diff($found, $declared);

        if ([] !== $stray) {
            throw new InvalidConfigurationException(\sprintf('Fichier d\'équilibrage sans schéma dans "%s" : %s. Déclarer sa section dans App\Kernel::build(), ou le supprimer.', $this->directory, implode(', ', $stray)));
        }
    }

    /**
     * Le hash porte sur la configuration **normalisée** — après validation, valeurs par
     * défaut comprises — et non sur les octets des fichiers. Deux conséquences voulues :
     * reformater un YAML ou y écrire un commentaire ne change pas la version, alors qu'un
     * réglage qui n'était pas écrit et dont le défaut bouge, si. C'est bien l'équilibrage
     * *effectif* qu'on date, pas le fichier.
     *
     * Préfixé par le nom du dossier : `v1-3f2a9c1d4b7e` dit d'où vient la balance autant
     * que ce qu'elle vaut.
     *
     * @param array<string, array<string, mixed>> $values
     */
    private function version(array $values): string
    {
        $canonical = json_encode(self::canonicalize($values), \JSON_THROW_ON_ERROR);

        return basename($this->directory).'-'.substr(hash('sha256', $canonical), 0, 12);
    }

    /**
     * Un tableau de clés n'a pas d'ordre signifiant : on le trie, sans quoi permuter deux
     * réglages dans le YAML changerait le `rulesetVersion` sans rien changer au jeu. Une
     * liste, si : l'ordre des paliers d'une courbe de niveaux *est* la donnée.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::canonicalize($value);
            }
        }

        return $values;
    }
}
