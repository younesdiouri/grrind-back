<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Uid\Uuid;

/**
 * Un compte ouvert pour les besoins d'un test : son identifiant et de quoi parler en
 * son nom. Les deux vont ensemble — dès qu'un test écrit, il lui faut l'en-tête pour
 * appeler et l'UUID pour vérifier ce qui a été écrit.
 */
final readonly class Account
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public Uuid $id,
        public array $headers,
    ) {
    }
}
