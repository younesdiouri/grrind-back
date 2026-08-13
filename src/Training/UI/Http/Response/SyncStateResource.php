<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Ce dont le client a besoin pour demander la bonne fenêtre à HealthKit ou Health Connect.
 *
 * ————— Pourquoi le curseur vient du serveur ——————————————————————————————————————————————
 *
 * Le client peut garder sa date de dernière synchronisation en local, et il le fera pour
 * éviter un aller-retour au démarrage. Mais il ne doit pas en **dépendre** : réinstallation,
 * changement d'appareil, second appareil sur le même compte. Le serveur sait ce qu'il a
 * reçu ; il est la seule source qui survit à tout ça. Le cache local est une optimisation,
 * pas la vérité.
 *
 * ————— Pourquoi la fenêtre est servie et pas codée en dur ————————————————————————————————
 *
 * Même raison que pour la table de correspondance des activités : elle doit pouvoir bouger
 * sans publication sur les stores. Une app qui demanderait trente jours à HealthKit pendant
 * que le serveur en accepte soixante enverrait moins que ce qu'elle pourrait, et l'inverse
 * enverrait du travail pour rien.
 */
final readonly class SyncStateResource
{
    public function __construct(
        public ?DateTimeImmutable $lastImportedAt,
        public int $importWindowDays,
    ) {
    }

    /**
     * @return array{lastImportedAt: string|null, importWindowDays: int}
     */
    public function toArray(): array
    {
        return [
            // La fin du workout le plus récent que le serveur ait en base — pas la date du
            // dernier appel d'import, qui a pu ne rien apporter. `null` sur un compte qui
            // n'a jamais synchronisé : le client demande alors toute la fenêtre.
            'lastImportedAt' => $this->lastImportedAt?->format(DateTimeInterface::ATOM),
            'importWindowDays' => $this->importWindowDays,
        ];
    }
}
