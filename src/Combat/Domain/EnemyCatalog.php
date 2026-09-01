<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use App\Shared\Application\GameRulesets;
use InvalidArgumentException;

/**
 * Le catalogue des adversaires PvE, chargé depuis le snapshot de jeu publié — les ennemis
 * ordinaires **et**, depuis le #219, les boss.
 *
 * **Un catalogue, pas une table.** Les adversaires ne vivent pas en base — même geste que
 * {@see \App\Progression\Domain\TitleCatalog} pour les titres : ajouter un ennemi ou un boss
 * est un déploiement, pas un INSERT.
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
 * ## Deux listes, une seule classe `Enemy` (#219)
 *
 * `bosses:` est un second bloc de le snapshot publié, chargé à côté de `enemies:` — pas dedans.
 * La raison est un invariant, pas une préférence d'écriture : `enemies:` refuse deux entrées
 * au même niveau, et c'est ce qui garantit que `forLevel()` rend toujours un adversaire et un
 * seul. Un boss posé au niveau d'un ennemi existant casserait cet invariant s'il partageait
 * la même liste. Séparés, `enemies:` garde sa règle intacte — unique par **niveau** — et
 * `bosses:` a la sienne — unique par **clé**, sans aucune contrainte de niveau, puisqu'un
 * boss ne se tire jamais automatiquement, il se choisit.
 *
 * Les deux listes produisent pourtant le même objet {@see Enemy} : un boss n'a ni forme ni
 * champ de plus qu'un ennemi ordinaire, seule la **lecture** de `level` change — le palier
 * auquel `forLevel()` le choisirait tout seul pour un ennemi, le niveau minimum requis pour
 * en affronter un pour un boss (`minimum_level` dans le YAML). Écrire une seconde classe pour
 * cette seule différence de vocabulaire aurait dupliqué `Fighter`, les trois refus ci-dessous
 * et `FighterFactory::forEnemy()` sans rien y gagner.
 *
 * **Une clé ne peut pas exister des deux côtés.** `find()` et `findBoss()` doivent rester
 * sans ambiguïté chacun de son côté ; une clé qui apparaîtrait dans les deux listes rendrait
 * la réponse à « qui répond à cette clé ? » dépendante de la méthode appelée. C'est une
 * erreur de config, refusée au démarrage comme les autres.
 *
 * ## Les mêmes trois refus que `CombatRules`, et pour la même raison (#210, #218, #219)
 *
 * Un adversaire — ennemi ou boss — entre dans la boucle de {@see BattleSimulator} par la
 * même porte qu'un combattant dérivé — voir
 * {@see \App\Combat\Application\FighterFactory::forEnemy()} — donc l'invariant qui protège la
 * terminaison sur ses propres mérites doit valoir des deux côtés, et pour les deux listes.
 * Le schéma de configuration (`CombatSection`) ne borne `mitigation_permille`,
 * `extra_turn_permille` et `dodge_permille` que par le bas ; c'est ici, pas dans le YAML,
 * que le catalogue refuse ce que {@see CombatRules} refuse déjà au combattant dérivé : à
 * 1000 ‰ de mitigation, un adversaire devient invulnérable ; à 1000 ‰ de tour supplémentaire,
 * il ne rend jamais la main ; à 1000 ‰ d'esquive, il n'encaisse plus jamais rien.
 */
final class EnemyCatalog
{
    /** @var array<string, Enemy> ennemis ordinaires, par clé, dans l'ordre de déclaration */
    private array $byKey;

    /** @var array<int, Enemy> ennemis ordinaires, par niveau */
    private array $byLevel;

    /** @var array<string, Enemy> boss, par clé, dans l'ordre de déclaration */
    private array $byBossKey;

    private ?GameRulesets $rulesets;

    private ?self $historical = null;

    private ?self $available = null;

    private ?int $runtimeRevision = null;

    /**
     * @param list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}>         $enemies
     * @param list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $enemies, array $bosses = [], ?GameRulesets $rulesets = null)
    {
        $this->rulesets = $rulesets;
        if (null !== $rulesets) {
            $this->byKey = [];
            $this->byLevel = [];
            $this->byBossKey = [];

            return;
        }
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

            self::refuseIfUnwinnable($enemy);

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

        $byBossKey = [];

        foreach ($bosses as $entry) {
            // `minimum_level` alimente le même champ `level` qu'un ennemi ordinaire — voir
            // le docblock de la classe pour pourquoi une seule forme sert les deux listes.
            $boss = new Enemy(
                $entry['key'],
                $entry['minimum_level'],
                $entry['hp'],
                $entry['damage'],
                $entry['mitigation_permille'],
                $entry['extra_turn_permille'],
                $entry['dodge_permille'],
            );

            self::refuseIfUnwinnable($boss);

            if (isset($byBossKey[$boss->key])) {
                throw new InvalidArgumentException(\sprintf('Deux boss pour la clé "%s".', $boss->key));
            }

            // Voir le docblock de la classe : `find()` et `findBoss()` doivent rester sans
            // ambiguïté, une clé des deux côtés leur ferait rendre des réponses différentes
            // à la même question selon celle qu'on appelle.
            if (isset($byKey[$boss->key])) {
                throw new InvalidArgumentException(\sprintf('"%s" désigne à la fois un ennemi et un boss : la clé doit rester dans un seul des deux blocs.', $boss->key));
            }

            $byBossKey[$boss->key] = $boss;
        }

        $this->byKey = $byKey;
        $this->byLevel = $byLevel;
        $this->byBossKey = $byBossKey;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return new self([], [], $rulesets);
    }

    public function find(string $key): ?Enemy
    {
        if (null !== $this->rulesets) {
            return $this->current()->find($key);
        }

        return $this->byKey[$key] ?? null;
    }

    /** Résolution d'un snapshot de combat historique. */
    public function findHistorical(string $key): ?Enemy
    {
        return $this->find($key);
    }

    /** Choix explicite d'un ennemi jouable, donc actif. */
    public function findAvailable(string $key): ?Enemy
    {
        if (null !== $this->rulesets) {
            return $this->active()->find($key);
        }

        return $this->find($key);
    }

    /** Le pendant de {@see find()} pour un boss — voir le docblock de la classe. */
    public function findBoss(string $key): ?Enemy
    {
        if (null !== $this->rulesets) {
            return $this->current()->findBoss($key);
        }

        return $this->byBossKey[$key] ?? null;
    }

    /** Choix explicite d'un boss jouable, donc actif. */
    public function findAvailableBoss(string $key): ?Enemy
    {
        if (null !== $this->rulesets) {
            return $this->active()->findBoss($key);
        }

        return $this->findBoss($key);
    }

    /**
     * Le catalogue des ennemis ordinaires, dans l'ordre de déclaration — ce que le test de
     * couverture des traductions parcourt pour vérifier qu'aucun ennemi n'est livré sans nom.
     * Les boss ont leur propre liste, {@see bosses()} : ils ne sont jamais tirés
     * automatiquement, donc jamais concernés par ce que `all()` sert historiquement, voir
     * {@see forLevel()}.
     *
     * @return list<Enemy>
     */
    public function all(): array
    {
        if (null !== $this->rulesets) {
            return $this->active()->all();
        }

        return array_values($this->byKey);
    }

    /**
     * Le catalogue des boss, dans l'ordre de déclaration — même rôle que {@see all()} pour
     * `bosses:`. `GET /api/enemies` et le test de couverture des traductions le parcourent
     * pour qu'aucun boss ne soit livré sans nom.
     *
     * @return list<Enemy>
     */
    public function bosses(): array
    {
        if (null !== $this->rulesets) {
            return $this->active()->bosses();
        }

        return array_values($this->byBossKey);
    }

    /**
     * L'ennemi opposé à un joueur de ce niveau — voir le docblock de la classe pour le
     * choix retenu quand aucune entrée n'existe pour ce niveau exact.
     *
     * Ne porte jamais sur les boss : aucun boss n'est un candidat de la sélection
     * automatique, voir le docblock de la classe.
     */
    public function forLevel(int $playerLevel): Enemy
    {
        if (null !== $this->rulesets) {
            return $this->active()->forLevel($playerLevel);
        }
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

    private function current(): self
    {
        $revision = $this->rulesets?->revision();
        \assert(\is_int($revision));
        if (null !== $this->historical && $revision === $this->runtimeRevision) {
            return $this->historical;
        }
        $snapshot = $this->rulesets?->snapshot();
        \assert(\is_array($snapshot));
        /** @var array{combat: array{enemies: list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}>, bosses: list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}>}} $snapshot */
        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $enemies */ $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses */ $bosses = $snapshot['combat']['bosses'];

        $this->runtimeRevision = $revision;
        $this->available = null;

        return $this->historical = new self($enemies, $bosses);
    }

    private function active(): self
    {
        $this->current();
        if (null !== $this->available) {
            return $this->available;
        }
        $snapshot = $this->rulesets?->snapshot();
        \assert(\is_array($snapshot));
        /** @var array{combat: array{enemies: list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}>, bosses: list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}>}} $snapshot */
        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}> $enemies */
        $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int, active?: bool}> $bosses */
        $bosses = $snapshot['combat']['bosses'];

        return $this->available = new self(
            array_values(array_filter($enemies, static fn (array $enemy): bool => $enemy['active'] ?? true)),
            array_values(array_filter($bosses, static fn (array $enemy): bool => $enemy['active'] ?? true)),
        );
    }

    /**
     * Les mêmes trois refus, qu'il s'agisse d'un ennemi ordinaire ou d'un boss — voir le
     * docblock de la classe : les deux entrent dans la boucle de {@see BattleSimulator} par
     * la même porte, donc l'invariant qui protège sa terminaison doit valoir des deux côtés.
     */
    private static function refuseIfUnwinnable(Enemy $entry): void
    {
        // Un adversaire invulnérable ne perdrait jamais sur ses propres mérites.
        if ($entry->mitigationPermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('La mitigation de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $entry->key, $entry->mitigationPermille));
        }

        // Même conséquence, par l'autre chemin : un adversaire qui rejoue toujours son tour
        // ne rendrait jamais la main.
        if ($entry->extraTurnPermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('Le tour supplémentaire de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $entry->key, $entry->extraTurnPermille));
        }

        // Même conséquence, par un troisième chemin (#218) : un adversaire qui esquive
        // toujours n'encaisserait plus jamais rien.
        if ($entry->dodgePermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('L\'esquive de "%s" doit rester sous 1000 millièmes (100 %%), %d demandé.', $entry->key, $entry->dodgePermille));
        }
    }
}
