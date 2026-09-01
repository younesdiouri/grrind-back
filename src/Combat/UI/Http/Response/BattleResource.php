<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Battle;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use DateTimeInterface;

/**
 * Un combat, tel que le client l'anime — la timeline comprise, en un seul aller-retour.
 *
 * **L'ordre des champs est l'ordre de l'animation**, et c'est un contrat, pas une convention
 * d'écriture — même règle que {@see \App\Training\UI\Http\Response\RewardSummaryResource}.
 * {@see \App\Tests\Combat\UI\Http\Response\BattleResourcePayloadTest} fige cet ordre.
 *
 * **`rewards` vient directement de `Battle::$reward` (#227), jamais recalculé ici.** La
 * ligne porte déjà `{loot: [...], coins: {gained, before, after}}` — voir le docblock de
 * `Battle` pour pourquoi c'est persisté plutôt que rejoué depuis la graine. Une défaite ou
 * une victoire tranchée par `max_turns` sans KO portent la forme vide de
 * `App\Shared\Application\BattleDrop::none()`, jamais une clé absente — même argument que
 * `loot` sur le `RewardSummary`.
 *
 * **Le nom de l'ennemi se traduit depuis la clé du snapshot, jamais depuis
 * `EnemyCatalog`.** Un combat déjà joué est un fait écrit : sa lecture ne doit rien à l'état
 * *courant* d'un fichier de config-as-code qui, lui, continue de bouger — même raison que le
 * snapshot lui-même ne re-dérive pas les stats de l'ennemi au moment de la lecture (voir le
 * docblock de `Battle`). Consulter le catalogue ici forçait, en plus, un cas d'erreur qui
 * n'existait nulle part ailleurs dans le module : retirer ou renommer une entrée de
 * le snapshot publié — un geste que ce fichier annonce lui-même comme normal — rendait alors
 * illisible tout combat déjà joué contre cet ennemi. Le pire cas est désormais un nom qui
 * s'affiche comme sa clé de traduction si l'entrée disparaît aussi de
 * `translations/enemies.*.yaml` — dégradé et lisible, jamais un 500. Même principe que
 * `RisalaResource`, qui rend `senderDisplayName` à `null` plutôt que de faire dépendre un
 * défi déjà envoyé de la présence actuelle de son expéditeur dans la guilde.
 */
final readonly class BattleResource
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
            'rewards' => $this->battle->reward(),
        ];
    }
}
