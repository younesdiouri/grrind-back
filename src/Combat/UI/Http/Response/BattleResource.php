<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Battle;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Shared\Application\ItemImageUrlResolver;
use App\Shared\Domain\Appearance;
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
 * une victoire tranchée par `max_attacks` sans KO portent la forme vide de
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
 *
 * **Un défi PvP (#283) passe par la même ressource, et c'est le point.** `enemy` garde sa
 * forme exacte — `name` vaut le pseudo snapshoté, `imageUrls` et `introduction` valent `null`
 * comme pour un ennemi sans présentation publiée — pour que le client anime un défi avec le
 * composant qu'il a déjà écrit, sans une branche. Ce qui les distingue est un couple :
 * `key` nul et `playerId` renseigné pour un défi, l'inverse pour un combat PvE.
 */
final readonly class BattleResource
{
    /** @param array{idle: string, attack: string, hit: string}|null $imageUrls */
    private function __construct(
        private Battle $battle,
        private string $enemyName,
        private ?ItemImageUrlResolver $items,
        private ?array $imageUrls,
        private ?string $introduction,
    ) {
    }

    public static function from(Battle $battle, EnemyTranslator $translator, ?ItemImageUrlResolver $items = null): self
    {
        $key = $battle->enemySnapshot()['key'];

        // Un défi PvP (#283) n'a pas d'entrée de catalogue derrière lui : le pseudo est
        // snapshoté sur la ligne, et il n'y a ni illustration ni texte d'introduction à
        // résoudre. Le traducteur n'est donc pas consulté — il n'aurait rien à traduire, et
        // lui passer une clé nulle demanderait à `EnemyTranslator` de connaître le PvP.
        if (null === $key) {
            return new self($battle, $battle->enemySnapshot()['name'] ?? '', $items, null, null);
        }

        return new self($battle, $translator->nameOf($key), $items, $translator->imageUrlsOf($key), $translator->introductionOf($key));
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
            'attackCount' => $this->battle->attackCount(),
            'actionCount' => $this->battle->actionCount(),
            'elapsedTicks' => $this->battle->elapsedTicks(),
            'endReason' => $this->battle->endReason()->value,
            'algorithmVersion' => $this->battle->algorithmVersion(),
            'foughtAt' => $this->battle->foughtAt()->format(DateTimeInterface::ATOM),
            // L'apparence ferme la marche (#289) : une clé du catalogue `GET /api/appearances`,
            // figée au combat. Un combat antérieur à #289 n'en porte pas et montre le défaut.
            'player' => [
                ...FighterResource::from($this->battle->playerSnapshot()['fighter'])->toArray(),
                'appearance' => Appearance::fromSnapshot($this->battle->playerSnapshot()['appearance'] ?? null)->value,
            ],
            // `key` et `name` d'abord, puis les mêmes quatre champs que `player` — voir le
            // docblock de la classe pour pourquoi le nom est résolu ici plutôt que rendu tel
            // quel depuis le snapshot.
            'enemy' => array_merge(
                // `key` **ou** `playerId` : l'un des deux est toujours nul, et c'est ce qui
                // dit au client s'il affronte une entrée du catalogue ou un co-équipier.
                ['key' => $enemySnapshot['key'], 'playerId' => $this->battle->opponentId()?->toRfc4122(), 'name' => $this->enemyName],
                FighterResource::from($enemySnapshot['fighter'])->toArray(),
                ['imageUrls' => $this->imageUrls, 'introduction' => $this->introduction],
                // Le héros du défié pour un duel, `null` face au catalogue — même règle que
                // `key` / `playerId`.
                ['appearance' => null === $enemySnapshot['key'] ? Appearance::fromSnapshot($enemySnapshot['appearance'] ?? null)->value : null],
            ),
            'events' => BattleEventResource::listOf($this->battle->timeline()),
            'rewards' => $this->rewards(),
        ];
    }

    /** @return array<string, mixed> */
    private function rewards(): array
    {
        $rewards = $this->battle->reward();
        if (null === $this->items || !isset($rewards['loot']) || !\is_array($rewards['loot'])) {
            return $rewards;
        }
        foreach ($rewards['loot'] as &$item) {
            if (\is_array($item) && !isset($item['imageUrl']) && \is_string($item['key'] ?? null)) {
                $item['imageUrl'] = $this->items->imageUrlOf($item['key']);
            }
        }
        unset($item);

        return $rewards;
    }
}
