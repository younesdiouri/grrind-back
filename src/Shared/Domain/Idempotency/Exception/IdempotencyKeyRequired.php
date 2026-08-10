<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency\Exception;

use App\Shared\Domain\Exception\DomainError;
use App\Shared\Domain\Idempotency\IdempotencyRecord;

/**
 * L'en-tête `Idempotency-Key` manque, est vide ou dépasse la longueur admise —
 * autrement dit, le client n'a pas fourni de quoi reconnaître un rejeu. 400 : la
 * requête est mal formée, personne n'a encore rien écrit.
 *
 * Un seul type d'erreur pour les trois cas, parce que le correctif côté client est
 * le même : envoyer un identifiant exploitable, généré une fois par action de
 * l'utilisateur et réutilisé tel quel à chaque tentative.
 */
final class IdempotencyKeyRequired extends DomainError
{
    public function __construct()
    {
        parent::__construct(
            'L\'en-tête Idempotency-Key est obligatoire sur cette écriture, non vide et d\'au plus '
            .IdempotencyRecord::KEY_MAX_LENGTH.' caractères.',
            ['maxLength' => IdempotencyRecord::KEY_MAX_LENGTH],
        );
    }

    public function type(): string
    {
        return 'idempotency-key-required';
    }
}
