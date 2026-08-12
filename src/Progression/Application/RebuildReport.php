<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\SnapshotDivergence;

/**
 * Ce qu'une passe de reconstruction a trouvé, et ce qu'elle en a fait.
 *
 * **Les écarts sont comptés tous, gardés quelques-uns.** Un rapport de dix mille lignes ne
 * se lit pas, et s'il y en a dix mille, ce n'est plus une ligne à réparer mais un bug de
 * projection à corriger — le compteur suffit alors à le dire, et les premiers exemples à
 * montrer de quoi il s'agit. Garder tout ferait surtout tenir la base entière en mémoire au
 * moment précis où elle va mal.
 */
final readonly class RebuildReport
{
    /** Au-delà, on ne garde plus d'exemple : le compteur porte l'information. */
    public const int SAMPLE_SIZE = 20;

    /**
     * @param int                      $checked  comptes examinés
     * @param int                      $diverged comptes en écart, y compris ceux dont l'exemple n'a pas été gardé
     * @param int                      $repaired comptes réécrits — toujours `0` en `--dry-run`
     * @param list<SnapshotDivergence> $samples  les premiers écarts, pour montrer de quoi il s'agit
     */
    public function __construct(
        public int $checked,
        public int $diverged,
        public int $repaired,
        public array $samples,
    ) {
    }

    public function isCoherent(): bool
    {
        return 0 === $this->diverged;
    }

    /** Vrai quand des écarts existent et que la passe n'a rien réécrit. */
    public function hasUnrepairedDivergences(): bool
    {
        return $this->diverged > $this->repaired;
    }
}
