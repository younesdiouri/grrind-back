<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use RuntimeException;

/**
 * Erreur métier attendue — pas un bug. Le domaine exprime une *sémantique*
 * (introuvable, conflit, règle violée) ; la traduction en statut HTTP appartient
 * à la couche UI, jamais au domaine.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * @param array<string, scalar|null> $context membres d'extension exposés tels quels dans le problem+json
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * Identifiant stable de l'erreur, en kebab-case : « email-already-used ».
     * C'est sur cette valeur que le client iOS branche ses messages — elle ne
     * change jamais, contrairement au message qui reste humain.
     */
    abstract public function type(): string;

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
