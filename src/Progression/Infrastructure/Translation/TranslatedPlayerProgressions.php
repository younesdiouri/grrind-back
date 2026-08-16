<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Translation;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Domain\TitleProgress;
use App\Progression\Infrastructure\Doctrine\ActiveTitleRepository;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use App\Shared\Application\PlayerProgression;
use App\Shared\Application\PlayerProgressions;
use App\Shared\Application\PlayerTitle;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see PlayerProgressions} : c'est par cette classe, et uniquement
 * par elle, que `Community` voit le niveau et le titre d'un joueur.
 *
 * Le pendant batch de {@see TranslatedPlayerTitles}, et il ne fait pas plus qu'elle :
 * assembler trois lectures et les mettre en mots. Ce qui sort d'ici est **déjà traduit et
 * déjà situé**, pour que `Community` n'ait ni catalogue à consulter ni condition à
 * interpréter.
 *
 * **Trois requêtes, quel que soit le nombre de joueurs.** C'est la propriété que le port
 * existe pour garantir, et elle est vérifiée par un test qui compte les requêtes d'une
 * guilde de deux membres puis d'une guilde de dix. Sans lui, la première refonte qui
 * transformerait une de ces lectures en boucle passerait la revue sans un mot.
 */
final readonly class TranslatedPlayerProgressions implements PlayerProgressions
{
    public function __construct(
        private ProgressionSnapshotRepository $snapshots,
        private ActiveTitleRepository $activeTitles,
        private UnlockedTitleRepository $unlockedTitles,
        private TitleCatalog $catalog,
        private LevelCurve $curve,
        private TitleTranslator $titles,
    ) {
    }

    /**
     * @param list<Uuid> $playerIds
     *
     * @return array<string, PlayerProgression>
     */
    public function of(array $playerIds): array
    {
        if ([] === $playerIds) {
            return [];
        }

        $standings = $this->snapshots->standingsOf($playerIds);
        $worn = $this->activeTitles->titleIdsOf($playerIds);
        $unlockedAt = $this->unlockedTitles->unlockedAtOf($playerIds, array_values(array_unique($worn)));

        $progressions = [];

        foreach ($playerIds as $playerId) {
            $key = $playerId->toRfc4122();
            $standing = $standings[$key] ?? null;

            // Le joueur qui n'a pas encore de ligne n'est pas une anomalie : il vient de
            // s'inscrire, c'est le premier crédit qui la pose, et il peut avoir rejoint
            // une guilde entre-temps. Le repli passe par la courbe plutôt que par la
            // constante de `PlayerProgression::untouched()`, parce qu'ici on l'a sous la
            // main et qu'un niveau de départ est de l'équilibrage comme le reste.
            $standing ??= $this->curve->standingAt(0);

            $progressions[$key] = new PlayerProgression(
                $standing->level,
                $standing->xpIntoLevel,
                $standing->xpToNextLevel,
                $this->wornTitleOf($playerId, $worn[$key] ?? null, $unlockedAt),
            );
        }

        return $progressions;
    }

    /**
     * Le titre porté, mis en mots — ou `null`, qui est un état parfaitement normal.
     *
     * **La barre d'un titre porté est pleine par construction** ({@see TitleProgress::completed()}),
     * donc aucune relecture du ledger n'est nécessaire pour l'afficher : c'est ce qui
     * permet de servir trente membres sans trente parcours de transactions.
     *
     * Un identifiant qui ne correspond à rien dans le catalogue rend `null` sans lever :
     * c'est ce qui arrive à un titre retiré du YAML dont la ligne survit en base, et ça ne
     * doit pas faire disparaître la liste entière.
     *
     * @param array<string, DateTimeImmutable> $unlockedAt
     */
    private function wornTitleOf(Uuid $playerId, ?string $titleId, array $unlockedAt): ?PlayerTitle
    {
        if (null === $titleId) {
            return null;
        }

        $title = $this->catalog->find($titleId);

        if (null === $title) {
            return null;
        }

        return $this->titles->describe(
            TitleProgress::completed($title),
            $unlockedAt[UnlockedTitleRepository::pairKey($playerId, $titleId)] ?? null,
        );
    }
}
