<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Met un nom sur un ennemi — même geste que
 * {@see \App\Progression\Infrastructure\Translation\TitleTranslator} pour les titres.
 *
 * **Traduit depuis la clé, jamais depuis un `Enemy` du catalogue.** Elle n'a jamais eu
 * besoin de plus que `$enemy->key` ; prendre un `Enemy` en argument forçait quiconque
 * rend un combat déjà joué à retrouver l'ennemi dans `EnemyCatalog::find()` pour en
 * extraire cette seule chaîne — un aller-retour inutile qui rendait, en plus, le rendu
 * d'un vieux combat dépendant de l'état *courant* du catalogue. Voir le docblock de
 * `BattleResource` pour ce que ça a coûté.
 *
 * **Le seul endroit qui lit les libellés publiés.** Les traductions FR/EN sont incluses dans
 * le snapshot DB et indexées par clé d'ennemi ; l'affichage ne consulte donc aucun YAML de
 * présentation. Un test de couverture refuse une clé manquante, sans quoi le repli enverrait
 * `sand_jackal.name` au joueur, en silence et en production.
 *
 * La locale n'est pas un paramètre : le traducteur lit celle de la requête, négociée sur
 * `Accept-Language`.
 */
final class EnemyTranslator implements ResetInterface
{
    public const string DOMAIN = 'enemies';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $enemies = null;

    private ?int $revision = null;

    public function __construct(private readonly TranslatorInterface $translator, private readonly GameRulesets $rulesets, private readonly ?UrlGeneratorInterface $urls = null)
    {
    }

    /**
     * @param string $key la clé du catalogue (`Enemy::$key`) — pas nécessairement encore
     *                    présente dans `EnemyCatalog` : un combat déjà joué la porte dans
     *                    son snapshot indépendamment du catalogue courant
     */
    public function nameOf(string $key): string
    {
        $locale = substr($this->translator->getLocale(), 0, 2);
        /** @var array<string, array{name?: string}> $translations */
        $translations = $this->enemies()[$key]['translations'] ?? [];

        return $translations[$locale]['name']
            ?? $translations['en']['name']
            ?? $translations['fr']['name']
            // Une bataille historique peut référencer un ennemi retiré ; conserver la
            // clé de traduction rend ce cas identifiable sans prétendre le traduire.
            ?? strtolower($key).'.name';
    }

    /** @return array{idle: string, attack: string, hit: string}|null */
    public function imageUrlsOf(string $key): ?array
    {
        $paths = $this->enemies()[$key]['image_paths'] ?? null;
        if (!\is_array($paths) || null === $this->urls) {
            return null;
        }
        $urls = [];
        foreach (['idle', 'attack', 'hit'] as $pose) {
            $path = $paths[$pose] ?? null;
            if (!\is_string($path) || '' === $path || 'placeholder.png' === $path) {
                return null;
            }
            $urls[$pose] = $this->urls->generate('game_image', ['name' => $path], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $urls;
    }

    public function introductionOf(string $key): ?string
    {
        /** @var array<string, array{introduction?: ?string}> $translations */
        $translations = $this->enemies()[$key]['translations'] ?? [];
        foreach ([substr($this->translator->getLocale(), 0, 2), 'en', 'fr'] as $locale) {
            $text = $translations[$locale]['introduction'] ?? null;
            if (\is_string($text) && 1 !== preg_match('/^[\s\p{Z}]*$/u', $text)) {
                return $text;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->enemies = null;
        $this->revision = null;
    }

    /** @return array<string, array<string, mixed>> */
    private function enemies(): array
    {
        $revision = $this->rulesets->revision();
        if (null !== $this->enemies && $revision === $this->revision) {
            return $this->enemies;
        }
        $snapshot = $this->rulesets->snapshot();
        $indexed = [];
        $combat = $snapshot['combat'] ?? [];
        \assert(\is_array($combat));
        foreach (['enemies', 'bosses'] as $kind) {
            $enemies = $combat[$kind] ?? [];
            \assert(\is_array($enemies));
            foreach ($enemies as $enemy) {
                \assert(\is_array($enemy));
                /** @var array<string, mixed> $enemy */
                \assert(\is_string($enemy['key']));
                $indexed[$enemy['key']] = $enemy;
            }
        }

        $this->revision = $revision;

        return $this->enemies = $indexed;
    }
}
