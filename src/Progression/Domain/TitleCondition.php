<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Ce qu'il faut atteindre pour porter un titre. **Fonction pure** : un relevé entre, un
 * nombre sort, et la comparaison au seuil se fait dessus.
 *
 * La condition rend une **progression** et pas un booléen. C'est ce qui permet d'afficher
 * « 12 séances sur 25 » sans dupliquer ailleurs la règle qui dit ce qu'on compte : le
 * déblocage et la barre de progression lisent la même méthode, donc ils ne peuvent pas
 * diverger. Un titre qui s'annoncerait à 24/25 sans se débloquer à 25 serait un bug qu'on
 * ne verrait qu'en production.
 */
final readonly class TitleCondition
{
    public function __construct(
        public TitleRequirement $requirement,
        public int $threshold,
        public ?Discipline $discipline = null,
    ) {
        // Un seuil à zéro décrit un titre que tout le monde possède dès l'inscription : ce
        // n'est pas une récompense, c'est du bruit dans le catalogue.
        if ($threshold < 1) {
            throw new InvalidArgumentException(\sprintf('Un titre ne se débloque pas à un seuil de %d.', $threshold));
        }

        if (null === $discipline && $requirement->needsDiscipline()) {
            throw new InvalidArgumentException(\sprintf('La condition "%s" doit nommer sa discipline.', $requirement->value));
        }

        // Le silence serait le pire des cas : une discipline posée sur une condition qui ne
        // la lit pas ferait croire à un titre spécialisé, et il se débloquerait sur le
        // total de tout le monde.
        if (null !== $discipline && !$requirement->acceptsDiscipline()) {
            throw new InvalidArgumentException(\sprintf('La condition "%s" ne se restreint pas à une discipline.', $requirement->value));
        }
    }

    /** Où en est ce joueur, dans l'unité du seuil. Peut dépasser le seuil, et le dépasse. */
    public function progressOf(PlayerRecord $record): int
    {
        return match ($this->requirement) {
            TitleRequirement::LevelReached => $record->level,
            TitleRequirement::TotalXp => $record->totalXp,
            TitleRequirement::SessionCount => $record->sessionsIn($this->discipline),
            // La discipline est garantie non nulle par le constructeur ; l'assertion le dit
            // à l'analyse statique autant qu'au lecteur.
            TitleRequirement::DisciplineSeconds => $record->secondsIn($this->discipline ?? throw new InvalidArgumentException('Condition de temps sans discipline.')),
        };
    }

    public function isMetBy(PlayerRecord $record): bool
    {
        return $this->progressOf($record) >= $this->threshold;
    }
}
