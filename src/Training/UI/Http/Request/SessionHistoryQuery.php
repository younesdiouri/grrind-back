<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\Discipline;
use App\Training\Domain\SessionStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de `GET /api/training/sessions`, tous facultatifs : sans aucun d'eux,
 * le joueur reçoit sa première page d'historique complet.
 *
 * Rien n'est validé à la main. Les valeurs fermées sont typées par leur enum, le curseur
 * par `Uuid` et la fenêtre par `DateTimeImmutable` : le Serializer refuse ce qui n'entre
 * pas dans le type, et `#[MapQueryString]` transforme ce refus en 422 nommant le
 * paramètre fautif. C'est le même contrat d'erreur que les payloads JSON du reste de
 * l'API, sans une ligne de contrôle en plus.
 *
 * Les bornes sont des **instants**, pas des dates : le client envoie son décalage
 * (`2026-07-01T00:00:00+02:00`). Une date nue serait lue à minuit UTC, ce qui décalerait
 * la fenêtre d'un joueur parisien de deux heures — le fuseau du joueur sert au streak,
 * pas à réinterpréter ce qu'il a explicitement demandé.
 *
 * @see https://symfony.com/doc/current/controller.html#mapping-query-parameters-individually
 */
final readonly class SessionHistoryQuery
{
    /**
     * Une page pleine tient un écran de profil ; le plafond existe pour qu'un client
     * ne puisse pas demander tout l'historique en une requête.
     */
    public const int MAX_LIMIT = 50;

    public function __construct(
        public ?SessionStatus $status = null,
        public ?Discipline $discipline = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?Uuid $cursor = null,
        #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
        public int $limit = 20,
    ) {
    }
}
