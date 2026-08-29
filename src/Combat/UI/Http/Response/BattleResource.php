<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Battle;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use DateTimeInterface;

/**
 * Un combat, tel que le client l'anime — la timeline comprise, en un seul aller-retour.
 *
 * **L'ordre des champs est l'ordre de l'animation**, et c'est un contrat, pas une convention
 * d'écriture — même règle que {@see \App\Training\UI\Http\Response\RewardSummaryResource}.
 * {@see \App\Tests\Combat\UI\Http\Response\BattleResourcePayloadTest} fige cet ordre.
 *
 * **`rewards` est présent et vide dès maintenant.** Aucune récompense en V1 — voir le
 * docblock de {@see \App\Combat\Application\FightBattleHandler} — mais il y en aura une.
 * Le déclarer plus tard forcerait tout client déjà déployé à le traiter comme optionnel
 * pour toujours ; même argument que `loot`, `streak` et `unlockableNodes` sur le
 * `RewardSummary`.
 *
 * **Le nom de l'ennemi arrive traduit.** Il se résout depuis {@see EnemyCatalog}, pas depuis
 * le snapshot persisté : celui-ci ne porte que la clé — voir le docblock de `Battle` pour
 * pourquoi les stats d'un ennemi ne sont pas re-dérivées à la lecture. Le catalogue est
 * additif (voir son docblock) : une clé déjà jouée y reste, donc `find()` ne rend jamais
 * `null` pour un combat réellement écrit par {@see \App\Combat\Application\FightBattleHandler}.
 */
final readonly class BattleResource
{
    private function __construct(
        private Battle $battle,
        private string $enemyName,
    ) {
    }

    public static function from(Battle $battle, EnemyCatalog $enemies, EnemyTranslator $translator): self
    {
        $key = $battle->enemySnapshot()['key'];
        $enemy = $enemies->find($key);
        \assert(null !== $enemy, \sprintf('L\'ennemi "%s" a disparu du catalogue.', $key));

        return new self($battle, $translator->nameOf($enemy));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $enemySnapshot = $this->battle->enemySnapshot();

        return [
            'id' => $this->battle->id()->toRfc4122(),
            'result' => $this->battle->result()->value,
            'turns' => $this->battle->turns(),
            'foughtAt' => $this->battle->foughtAt()->format(DateTimeInterface::ATOM),
            'player' => FighterResource::from($this->battle->playerSnapshot()['fighter'])->toArray(),
            // `key` et `name` d'abord, puis les mêmes quatre champs que `player` — voir le
            // docblock de la classe pour pourquoi le nom est résolu ici plutôt que rendu tel
            // quel depuis le snapshot.
            'enemy' => array_merge(
                ['key' => $enemySnapshot['key'], 'name' => $this->enemyName],
                FighterResource::from($enemySnapshot['fighter'])->toArray(),
            ),
            'events' => BattleEventResource::listOf($this->battle->timeline()),
            'rewards' => [],
        ];
    }
}
