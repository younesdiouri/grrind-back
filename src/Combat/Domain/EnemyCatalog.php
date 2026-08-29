<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/**
 * Le catalogue des ennemis PvE, chargé depuis `config/game/v1/combat.yaml`.
 *
 * **Un catalogue, pas une table.** Les ennemis ne vivent pas en base — même geste que
 * {@see \App\Progression\Domain\TitleCatalog} pour les titres : ajouter un ennemi est un
 * déploiement, pas un INSERT.
 *
 * ## Un ennemi par niveau, pas une courbe
 *
 * Ses stats sont écrites en clair par palier de niveau, jamais dérivées d'une formule : le
 * ticket #208 l'assume — les caractéristiques d'un joueur sont des totaux d'XP répartis,
 * sans borne, et une dérivation linéaire de l'ennemi ne fonctionne que **parce que l'ennemi
 * est choisi au niveau du joueur**, les deux montant ensemble. Le jour où un ennemi de
 * palier fixe existera, il faudra une courbe — ce sera un ticket, pas une rustine ici.
 *
 * ## Le choix retenu pour `forLevel()`
 *
 * Le catalogue n'a pas d'entrée pour chaque niveau possible — un compte peut dépasser le
 * niveau du dernier ennemi livré. `forLevel()` rend alors le plus haut niveau du catalogue
 * qui ne dépasse pas celui du joueur, jamais `null` : le niveau 1 est garanti présent (voir
 * ci-dessous), donc il existe toujours un candidat. C'est un choix de ce ticket, pas un
 * énoncé du #209 — un nouveau palier d'ennemi qui comble l'écart reste un ajout de config,
 * pas un changement de comportement.
 *
 * ## Les mêmes trois refus que `CombatRules`, et pour la même raison (#210, #218)
 *
 * Un ennemi entre dans la boucle de {@see BattleSimulator} par la même porte qu'un
 * combattant dérivé — voir {@see \App\Combat\Application\FighterFactory::forEnemy()} —
 * donc l'invariant qui protège la terminaison sur ses propres mérites doit valoir des deux
 * côtés. Le schéma de configuration (`CombatSection`) ne borne `mitigation_permille`,
 * `extra_turn_permille` et `dodge_permille` que par le bas ; c'est ici, pas dans le YAML,
 * que le catalogue refuse ce que {@see CombatRules} refuse déjà au combattant dérivé : à
 * 1000 ‰ de mitigation, un ennemi devient invulnérable ; à 1000 ‰ de tour supplémentaire, il
 * ne rend jamais la main ; à 1000 ‰ d'esquive, il n'encaisse plus jamais rien.
 */
final readonly class EnemyCatalog
{
    /** @var array<string, Enemy> par clé, dans l'ordre de déclaration */
    private array $byKey;

    /** @var array<int, Enemy> par niveau */
    private array $byLevel;

    /**
     * @param list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $enemies
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $enemies)
    {
        // Un catalogue vide ne propose aucun combat : mieux vaut refuser de démarrer que
        // de laisser `forLevel()` n'avoir personne à rendre.
        if ([] === $enemies) {
            throw new InvalidArgumentException('Un catalogue d\'ennemis vide ne propose aucun combat.');
        }

        $byKey = [];
        $byLevel = [];

        foreach ($enemies as $entry) {
            $enemy = new Enemy(
                $entry['key'],
                $entry['level'],
                $entry['hp'],
                $entry['damage'],
                $entry['mitigation_permille'],
                $entry['extra_turn_permille'],
                $entry['dodge_permille'],
            );

            // Même conséquence que pour le combattant dérivé, voir le docblock de la
            // classe : un ennemi invulnérable ne perdrait jamais sur ses propres mérites.
            if ($enemy->mitigationPermille >= 1000) {
                throw new InvalidArgumentException(\sprintf('La mitigation de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $enemy->key, $enemy->mitigationPermille));
            }

            // Même conséquence, par l'autre chemin : un ennemi qui rejoue toujours son tour
            // ne rendrait jamais la main.
            if ($enemy->extraTurnPermille >= 1000) {
                throw new InvalidArgumentException(\sprintf('Le tour supplémentaire de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $enemy->key, $enemy->extraTurnPermille));
            }

            // Même conséquence, par un troisième chemin (#218) : un ennemi qui esquive
            // toujours n'encaisserait plus jamais rien.
            if ($enemy->dodgePermille >= 1000) {
                throw new InvalidArgumentException(\sprintf('L\'esquive de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $enemy->key, $enemy->dodgePermille));
            }

            if (isset($byLevel[$enemy->level])) {
                throw new InvalidArgumentException(\sprintf('Deux ennemis pour le niveau %d : "%s" et "%s".', $enemy->level, $byLevel[$enemy->level]->key, $enemy->key));
            }

            $byKey[$enemy->key] = $enemy;
            $byLevel[$enemy->level] = $enemy;
        }

        // C'est le niveau qu'un compte neuf rencontre : son absence laisserait le premier
        // combat sans adversaire.
        if (!isset($byLevel[1])) {
            throw new InvalidArgumentException('Le catalogue d\'ennemis doit couvrir le niveau 1 : c\'est celui qu\'un compte neuf rencontre.');
        }

        $this->byKey = $byKey;
        $this->byLevel = $byLevel;
    }

    public function find(string $key): ?Enemy
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * Le catalogue entier, dans l'ordre de déclaration — ce que le test de couverture des
     * traductions parcourt pour vérifier qu'aucun ennemi n'est livré sans nom.
     *
     * @return list<Enemy>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /**
     * L'ennemi opposé à un joueur de ce niveau — voir le docblock de la classe pour le
     * choix retenu quand aucune entrée n'existe pour ce niveau exact.
     */
    public function forLevel(int $playerLevel): Enemy
    {
        $candidate = null;

        foreach ($this->byLevel as $level => $enemy) {
            if ($level <= $playerLevel && (null === $candidate || $level > $candidate->level)) {
                $candidate = $enemy;
            }
        }

        // Le niveau 1 est garanti présent par le constructeur, et tout niveau de joueur
        // réel est au moins 1 : il existe donc toujours un candidat.
        \assert(null !== $candidate);

        return $candidate;
    }
}
