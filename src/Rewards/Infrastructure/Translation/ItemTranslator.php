<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Met un nom sur un objet — même geste que
 * {@see \App\Combat\Infrastructure\Translation\EnemyTranslator} pour les ennemis.
 *
 * **Traduit depuis la clé, jamais depuis un `Item` du catalogue.** Une ligne d'inventaire
 * (#29) ne portera que la clé de l'objet qu'elle référence ; en repartir plutôt que de
 * retrouver l'objet dans `ItemCatalog::find()` évite un aller-retour inutile, et garde le
 * rendu d'un objet possédé indépendant de l'état *courant* du catalogue.
 *
 * **Le seul endroit qui connaît les clés de traduction.** Elles se déduisent de la clé de
 * l'objet — `sand_runner_boots.name` — donc ajouter un objet ne demande rien ici : le YAML
 * d'équilibrage et les deux catalogues de traduction suffisent. `ItemTranslationsTest`
 * refuse une clé manquante, sans quoi le repli du traducteur enverrait la clé brute au
 * joueur, en silence et en production.
 */
final class ItemTranslator implements ResetInterface
{
    /** Le domaine de traduction, donc le nom des fichiers : `translations/items.<locale>.yaml`. */
    public const string DOMAIN = 'items';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $items = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly GameRulesets $rulesets,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function nameOf(string $key): string
    {
        $locale = $this->translator->getLocale();
        $item = $this->item($key);
        /** @var array<string, array{name?: string}> $translations */
        $translations = $item['translations'] ?? [];
        $locale = substr($locale, 0, 2);
        $name = $translations[$locale]['name'] ?? $translations['en']['name'] ?? $translations['fr']['name'] ?? null;

        return \is_string($name) && '' !== $name ? $name : $key;
    }

    public function imageUrlOf(string $key): string
    {
        $path = $this->item($key)['image_path'] ?? 'placeholder.png';
        \assert(\is_string($path));

        return $this->urls->generate('game_image', ['name' => $path], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function reset(): void
    {
        $this->items = null;
    }

    /** @return array<string, mixed> */
    private function item(string $key): array
    {
        if (null !== $this->items) {
            return $this->items[$key] ?? [];
        }
        $snapshot = $this->rulesets->snapshot();
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        $indexed = [];
        foreach ($items as $item) {
            \assert(\is_string($item['key']));
            $indexed[$item['key']] = $item;
        }

        $this->items = $indexed;

        return $indexed[$key] ?? [];
    }
}
