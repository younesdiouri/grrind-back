<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Le catalogue des titres, chargé depuis le snapshot de jeu publié.
 *
 * **Un catalogue runtime, pas une table éditable.** Les titres proviennent du snapshot DB
 * publié, tandis que *qui a débloqué quoi et quand* reste persisté séparément. Un titre
 * validé peut donc être rétroactif sans migration de faits : sa condition s'évalue sur le
 * ledger, qui contient déjà tout l'historique.
 *
 * L'ordre de déclaration est **signifiant** : il départage les ex æquo quand il faut
 * désigner le prochain titre d'un joueur. Le chargeur d'équilibrage ne descend pas dans les
 * listes, donc `game.titles.titles` reste un paramètre unique et cet ordre survit au
 * conteneur compilé.
 */
final class TitleCatalog
{
    /** @var array<string, Title> par identifiant, dans l'ordre de déclaration */
    private array $titles;

    private ?GameRulesets $rulesets;

    private ?self $historical = null;

    private ?self $available = null;

    private ?int $runtimeRevision = null;

    /**
     * @param list<array{id: string, condition: array{type: string, threshold: int, discipline?: string|null}}> $titles
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $titles, ?GameRulesets $rulesets = null)
    {
        $this->rulesets = $rulesets;
        if (null !== $rulesets) {
            $this->titles = [];

            return;
        }
        if ([] === $titles) {
            throw new InvalidArgumentException('Un catalogue sans titre ne récompense personne.');
        }

        $catalog = [];

        foreach ($titles as $entry) {
            $title = new Title($entry['id'], self::conditionOf($entry['condition']));

            // Un doublon d'identifiant ferait taire silencieusement la première définition :
            // deux conditions écrites, une seule évaluée, et rien pour dire laquelle.
            if (isset($catalog[$title->id])) {
                throw new InvalidArgumentException(\sprintf('Titre en double au catalogue : "%s".', $title->id));
            }

            $catalog[$title->id] = $title;
        }

        $this->titles = $catalog;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return new self([], $rulesets);
    }

    /** @return list<Title> */
    public function all(): array
    {
        if (null !== $this->rulesets) {
            return $this->active()->all();
        }

        return array_values($this->titles);
    }

    public function find(string $id): ?Title
    {
        if (null !== $this->rulesets) {
            return $this->current()->find($id);
        }

        return $this->titles[$id] ?? null;
    }

    /** Résolution historique d'un titre déjà acquis. */
    public function findHistorical(string $id): ?Title
    {
        return $this->find($id);
    }

    /** Sélection d'un titre actif uniquement. */
    public function findAvailable(string $id): ?Title
    {
        if (null !== $this->rulesets) {
            return $this->active()->find($id);
        }

        return $this->find($id);
    }

    /**
     * Le catalogue entier, situé pour ce joueur — c'est ce que rend `GET /api/titles`.
     *
     * Tous les titres, débloqués ou non : un catalogue qui ne montrerait que l'atteint ne
     * donnerait rien à viser, et c'est précisément ce qu'on attend d'un mur de titres.
     *
     * @return list<TitleProgress>
     */
    public function progressOf(PlayerRecord $record): array
    {
        if (null !== $this->rulesets) {
            return $this->current()->progressOf($record);
        }

        return array_map(
            static fn (Title $title): TitleProgress => TitleProgress::of($title, $record),
            $this->all(),
        );
    }

    /**
     * Les titres que ce relevé satisfait **et** qui ne sont pas déjà acquis.
     *
     * @param list<string> $alreadyUnlocked
     *
     * @return list<Title>
     */
    public function newlyUnlockedBy(PlayerRecord $record, array $alreadyUnlocked): array
    {
        if (null !== $this->rulesets) {
            return $this->active()->newlyUnlockedBy($record, $alreadyUnlocked);
        }

        return array_values(array_filter(
            $this->all(),
            static fn (Title $title): bool => !\in_array($title->id, $alreadyUnlocked, true)
                && $title->condition->isMetBy($record),
        ));
    }

    /**
     * Le prochain titre à viser : parmi ceux qui restent, le plus avancé en proportion.
     *
     * Le front l'a demandé, et c'est la bonne demande — « il te manque 3 séances » motive là
     * où une liste de trente titres verrouillés décourage. À progression égale, l'ordre du
     * catalogue tranche, donc le résultat est déterministe.
     *
     * Rend `null` quand tout est débloqué : il n'y a alors plus rien à viser, et zéro
     * voudrait dire « à portée ».
     *
     * @param list<string> $alreadyUnlocked
     */
    public function nextFor(PlayerRecord $record, array $alreadyUnlocked): ?TitleProgress
    {
        if (null !== $this->rulesets) {
            return $this->active()->nextFor($record, $alreadyUnlocked);
        }
        $next = null;

        foreach ($this->all() as $title) {
            if (\in_array($title->id, $alreadyUnlocked, true)) {
                continue;
            }

            $progress = TitleProgress::of($title, $record);

            if (null === $next || $progress->isCloserThan($next)) {
                $next = $progress;
            }
        }

        return $next;
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
        /** @var list<array{id: string, condition: array{type: string, threshold: int, discipline?: string|null}}> $titles */
        $titles = $snapshot['titles'];

        $this->runtimeRevision = $revision;
        $this->available = null;

        return $this->historical = new self($titles);
    }

    private function active(): self
    {
        $this->current();
        if (null !== $this->available) {
            return $this->available;
        }
        $snapshot = $this->rulesets?->snapshot();
        \assert(\is_array($snapshot));
        /** @var list<array{id: string, active?: bool, condition: array{type: string, threshold: int, discipline?: string|null}}> $titles */
        $titles = $snapshot['titles'];

        return $this->available = new self(array_values(array_filter($titles, static fn (array $title): bool => $title['active'] ?? true)));
    }

    /**
     * @param array{type: string, threshold: int, discipline?: string|null} $condition
     */
    private static function conditionOf(array $condition): TitleCondition
    {
        $requirement = TitleRequirement::tryFrom($condition['type'])
            ?? throw new InvalidArgumentException(\sprintf('Condition de titre inconnue : "%s".', $condition['type']));

        $discipline = null;

        if (null !== ($name = $condition['discipline'] ?? null)) {
            $discipline = Discipline::tryFrom($name)
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue au catalogue des titres : "%s".', $name));
        }

        return new TitleCondition($requirement, $condition['threshold'], $discipline);
    }
}
