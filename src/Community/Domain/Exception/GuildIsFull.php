<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

/**
 * La guilde a atteint la capacité fixée par l'équilibrage. Un conflit et non une règle
 * violée : la requête était légitime, c'est l'état de la guilde qui la refuse — et il
 * peut changer sans que le client s'y prenne autrement.
 */
final class GuildIsFull extends ConflictError
{
    public function __construct(int $capacity)
    {
        parent::__construct(
            \sprintf('Cette guilde est complète (%d membres).', $capacity),
            ['capacity' => $capacity],
        );
    }

    public function type(): string
    {
        return 'guild-is-full';
    }
}
