<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Le catalogue des titres, chargé depuis `config/game/v1/titles.yaml`.
 *
 * **Un catalogue, pas une table.** Les titres ne vivent pas en base : ce qui est persisté,
 * c'est *qui a débloqué quoi et quand*. Ajouter un titre est un déploiement, pas un INSERT
 * — et c'est ce qui permet à un titre d'être rétroactif sans migration de données : la
 * condition s'évalue sur le ledger, qui contient déjà tout l'historique.
 *
 * L'ordre de déclaration est **signifiant** : il départage les ex æquo quand il faut
 * désigner le prochain titre d'un joueur. Le chargeur d'équilibrage ne descend pas dans les
 * listes, donc `game.titles.titles` reste un paramètre unique et cet ordre survit au
 * conteneur compilé.
 */
final readonly class TitleCatalog
{
    /** @var array<string, Title> par identifiant, dans l'ordre de déclaration */
    private array $titles;

    /**
     * @param list<array{id: string, condition: array{type: string, threshold: int, discipline?: string|null}}> $titles
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $titles)
    {
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

    /** @return list<Title> */
    public function all(): array
    {
        return array_values($this->titles);
    }

    public function find(string $id): ?Title
    {
        return $this->titles[$id] ?? null;
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
