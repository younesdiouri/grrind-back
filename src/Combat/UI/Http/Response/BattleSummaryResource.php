<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Battle;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use DateTimeInterface;

/**
 * Un combat, tel qu'une ligne d'historique le montre — **jamais la timeline**, voir le
 * docblock de {@see \App\Combat\UI\Http\ListBattlesController} pour pourquoi. Le client
 * choisit un combat sur cette forme, puis va chercher `GET /api/battles/{id}` pour l'animer.
 *
 * **L'ordre des champs est l'ordre d'une ligne de liste** — même contrat que
 * {@see BattleResource} pour la timeline complète. {@see \App\Tests\Combat\UI\Http\Response\BattleSummaryResourcePayloadTest}
 * fige cet ordre.
 *
 * **Le nom de l'ennemi se traduit depuis la clé du snapshot, jamais depuis `EnemyCatalog`** —
 * exactement la même correction, pour la même raison, que sur {@see BattleResource} : un
 * combat déjà joué est un fait écrit, il ne doit rien à un fichier de config qui continue de
 * bouger. Ne pas la défaire ici par commodité.
 */
final readonly class BattleSummaryResource
{
    private function __construct(
        private Battle $battle,
        private string $enemyName,
    ) {
    }

    public static function from(Battle $battle, EnemyTranslator $translator): self
    {
        return new self($battle, $translator->nameOf($battle->enemySnapshot()['key']));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->battle->id()->toRfc4122(),
            // Toujours VICTORY ou DEFEAT — voir le docblock de `BattleResult` : un combat se
            // termine toujours avec un vainqueur, y compris quand `max_turns` l'interrompt.
            'result' => $this->battle->result()->value,
            'enemy' => [
                'key' => $this->battle->enemySnapshot()['key'],
                'name' => $this->enemyName,
            ],
            'turns' => $this->battle->turns(),
            'foughtAt' => $this->battle->foughtAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
