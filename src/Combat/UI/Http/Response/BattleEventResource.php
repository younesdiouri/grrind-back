<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

/**
 * Un événement de la timeline, tel que le client le reçoit.
 *
 * **Elle ne retraduit rien.** {@see \App\Combat\Domain\Battle::timeline()} rend déjà, pour
 * chaque événement, exactement la forme du contrat HTTP — types en MAJUSCULES compris, voir
 * le docblock de `Battle` pour pourquoi c'est écrit à la main plutôt que casté. Cette classe
 * ne fait que nommer le passage, comme {@see FighterResource} et {@see BattleResource} le
 * font pour le reste de la charge utile, pour qu'aucune ressource HTTP du module ne lise un
 * tableau brut sans dire ce qu'il transporte.
 */
final readonly class BattleEventResource
{
    /**
     * @param array<string, mixed> $event
     */
    private function __construct(private array $event)
    {
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function from(array $event): self
    {
        return new self($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->event;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    public static function listOf(array $events): array
    {
        return array_map(static fn (array $event): array => self::from($event)->toArray(), $events);
    }
}
