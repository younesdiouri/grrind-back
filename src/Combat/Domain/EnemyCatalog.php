<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use App\Shared\Application\GameRulesets;
use InvalidArgumentException;

/** Catalogue publié, ennemis par niveau et boss par clé. Les effets directs sont
 * validés contre les mêmes plafonds que les joueurs. Aucun attribut n'est inventé.
 * Le choix automatique prend le plus haut palier <= niveau, le niveau 1 étant obligatoire. */
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
     * @param list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int}>         $enemies
     * @param list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int}> $bosses
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $enemies, array $bosses = [], ?GameRulesets $rulesets = null, ?CombatRules $combatRules = null)
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
                $entry['combo_permille'],
                $entry['dodge_permille'],
                $entry['maintenance_permille'] ?? 0,
                $entry['critical_chance_permille'] ?? 0,
                $entry['guard_permille'] ?? 0,
                $entry['critical_resistance_permille'] ?? 0,
                $entry['cooldown_reduction_permille'] ?? 0,
                $entry['precision_permille'] ?? 0,
            );

            self::refuseIfUnwinnable($enemy, $combatRules);

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
                $entry['combo_permille'],
                $entry['dodge_permille'],
                $entry['maintenance_permille'] ?? 0,
                $entry['critical_chance_permille'] ?? 0,
                $entry['guard_permille'] ?? 0,
                $entry['critical_resistance_permille'] ?? 0,
                $entry['cooldown_reduction_permille'] ?? 0,
                $entry['precision_permille'] ?? 0,
            );

            self::refuseIfUnwinnable($boss, $combatRules);

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
        /** @var array{combat: array{fighter: array<string, int>, enemies: list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}>, bosses: list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}>}} $snapshot */
        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int}> $enemies */ $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int}> $bosses */ $bosses = $snapshot['combat']['bosses'];

        $this->runtimeRevision = $revision;
        $this->available = null;

        return $this->historical = new self($enemies, $bosses, combatRules: CombatRules::fromSnapshot($snapshot['combat']['fighter']));
    }

    private function active(): self
    {
        $this->current();
        if (null !== $this->available) {
            return $this->available;
        }
        $snapshot = $this->rulesets?->snapshot();
        \assert(\is_array($snapshot));
        /** @var array{combat: array{fighter: array<string, int>, enemies: list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}>, bosses: list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}>}} $snapshot */
        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}> $enemies */
        $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, combo_permille: int, dodge_permille: int, maintenance_permille?: int, critical_chance_permille?: int, guard_permille?: int, critical_resistance_permille?: int, cooldown_reduction_permille?: int, precision_permille?: int, active?: bool}> $bosses */
        $bosses = $snapshot['combat']['bosses'];

        return $this->available = new self(
            array_values(array_filter($enemies, static fn (array $enemy): bool => $enemy['active'] ?? true)),
            array_values(array_filter($bosses, static fn (array $enemy): bool => $enemy['active'] ?? true)),
        );
    }

    /**
     * Les mêmes plafonds, qu'il s'agisse d'un ennemi ordinaire ou d'un boss — voir le
     * docblock de la classe : les deux entrent dans la boucle de {@see BattleSimulator} par
     * la même porte, donc l'invariant qui protège sa terminaison doit valoir des deux côtés.
     */
    private static function refuseIfUnwinnable(Enemy $entry, ?CombatRules $rules): void
    {
        if ($entry->hp < 1 || $entry->damage < 0) {
            throw new InvalidArgumentException('PV ou dégâts ennemis invalides.');
        }
        if ($entry->mitigationPermille < 0 || $entry->mitigationPermille > ($rules->mitigationCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux mitigation ennemi hors plafond publié.');
        }
        if ($entry->comboPermille < 0 || $entry->comboPermille > ($rules->comboCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux combo ennemi hors plafond publié.');
        }
        if ($entry->dodgePermille < 0 || $entry->dodgePermille > ($rules->dodgeCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux dodge ennemi hors plafond publié.');
        }
        if ($entry->maintenancePermille < 0 || $entry->maintenancePermille > ($rules->maintenanceCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux maintenance ennemi hors plafond publié.');
        }
        if ($entry->criticalChancePermille < 0 || $entry->criticalChancePermille > ($rules->criticalChanceCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux criticalChance ennemi hors plafond publié.');
        }
        if ($entry->guardPermille < 0 || $entry->guardPermille > ($rules->guardCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux guard ennemi hors plafond publié.');
        }
        if ($entry->criticalResistancePermille < 0 || $entry->criticalResistancePermille > ($rules->criticalResistanceCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux criticalResistance ennemi hors plafond publié.');
        }
        if ($entry->cooldownReductionPermille < 0 || $entry->cooldownReductionPermille > ($rules->cooldownReductionCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux cooldownReduction ennemi hors plafond publié.');
        }
        if ($entry->precisionPermille < 0 || $entry->precisionPermille > ($rules->precisionCapPermille ?? 999)) {
            throw new InvalidArgumentException('Taux precision ennemi hors plafond publié.');
        }
    }
}
