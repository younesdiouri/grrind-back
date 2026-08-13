<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une séance telle que le fournisseur santé l'a rendue au client.
 *
 * **Le client envoie le type brut du fournisseur, jamais une `Discipline`.** C'est ce qui
 * permet d'ouvrir un sport côté serveur sans publier sur les stores : la traduction est
 * dans `config/game/v1/activity_types.yaml` (#86), et l'app n'a rien à en savoir.
 *
 * **La durée n'est pas un champ.** Elle se dérive des deux bornes, et le serveur l'arbitre
 * (#91). L'accepter en entrée créerait une troisième valeur à réconcilier avec les deux
 * autres, et donnerait au client une prise sur ce qu'il gagne.
 *
 * Un type d'activité inconnu **n'est pas une erreur** — c'est un workout écarté, nommé au
 * joueur dans la réponse. Une `source` inconnue, elle, est un 422 : la traduction est un
 * réglage de jeu, l'énumération des sources est le contrat. Un client qui invente une
 * source a un bug, un client qui rapporte du curling fait son travail.
 */
final readonly class ImportedWorkoutRequest
{
    /**
     * `HKWorkout.uuid` côté Apple, `metadata.id` côté Health Connect. C'est lui qui porte
     * la protection contre le double crédit, donc il est exigé : sans lui, une
     * synchronisation rejouée crédite deux fois.
     */
    public const int EXTERNAL_ID_MAX_LENGTH = 128;

    /**
     * Les constantes des deux fournisseurs sont courtes — la plus longue tient en une
     * quarantaine de caractères. Le plafond n'est là que pour qu'un champ d'un mégaoctet
     * ne parte pas se faire chercher dans la table de traduction.
     */
    public const int ACTIVITY_TYPE_MAX_LENGTH = 64;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::EXTERNAL_ID_MAX_LENGTH)]
        public string $externalId = '',
        // Nullable avec un défaut nul plutôt que non nullable : sans ça, une valeur
        // absente donne une erreur de dénormalisation opaque au lieu d'un 422 qui nomme
        // le champ. `NotNull` fait le reste.
        #[Assert\NotNull]
        public ?WorkoutSource $source = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: self::ACTIVITY_TYPE_MAX_LENGTH)]
        public string $activityType = '',
        #[Assert\NotNull]
        public ?DateTimeImmutable $startedAt = null,
        #[Assert\NotNull]
        public ?DateTimeImmutable $endedAt = null,
        // Les mesures sont toutes facultatives, et c'est structurel : aucun appareil ne
        // fournit tout. Zéro et « non mesuré » ne se confondent pas — un tour de piste
        // plat a bien un dénivelé de zéro.
        #[Assert\PositiveOrZero]
        public ?int $distanceMeters = null,
        #[Assert\PositiveOrZero]
        public ?int $calories = null,
        #[Assert\PositiveOrZero]
        public ?int $elevationGainMeters = null,
        // Une borne haute large : c'est un garde-fou contre une unité mal convertie, pas
        // une opinion médicale sur le cardio d'un joueur.
        #[Assert\Range(min: 1, max: 300)]
        public ?int $averageHeartRate = null,
    ) {
    }
}
