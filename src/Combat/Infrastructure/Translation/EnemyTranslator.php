<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
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
 * **Le seul endroit qui connaît les clés de traduction.** Elles se déduisent de la clé de
 * l'ennemi — `sand_jackal.name` — donc ajouter un ennemi ne demande rien ici : le YAML
 * d'équilibrage et les deux catalogues de traduction suffisent. Un test de couverture
 * refuse une clé manquante, sans quoi le repli du traducteur enverrait `sand_jackal.name`
 * au joueur, en silence et en production.
 *
 * La locale n'est pas un paramètre : le traducteur lit celle de la requête, négociée sur
 * `Accept-Language`.
 */
final class EnemyTranslator implements ResetInterface
{
    public const string DOMAIN = 'enemies';

    /** @var array<string, array<string, array{name: string}>>|null */
    private ?array $translations = null;

    public function __construct(private readonly TranslatorInterface $translator, private readonly ?GameRulesets $rulesets = null)
    {
    }

    /**
     * @param string $key la clé du catalogue (`Enemy::$key`) — pas nécessairement encore
     *                    présente dans `EnemyCatalog` : un combat déjà joué la porte dans
     *                    son snapshot indépendamment du catalogue courant
     */
    public function nameOf(string $key): string
    {
        if (null === $this->rulesets) {
            return $this->translator->trans(strtolower($key).'.name', domain: self::DOMAIN);
        }
        $locale = substr($this->translator->getLocale(), 0, 2);
        $translations = $this->translations();

        return $translations[$key][$locale]['name']
            ?? $translations[$key]['en']['name']
            ?? $translations[$key]['fr']['name']
            // Une bataille historique peut référencer un ennemi retiré ; conserver la
            // clé de traduction rend ce cas identifiable sans prétendre le traduire.
            ?? strtolower($key).'.name';
    }

    public function reset(): void
    {
        $this->translations = null;
    }

    /** @return array<string, array<string, array{name: string}>> */
    private function translations(): array
    {
        if (null !== $this->translations) {
            return $this->translations;
        }
        \assert(null !== $this->rulesets);
        $snapshot = $this->rulesets->snapshot();
        $translations = [];
        $combat = $snapshot['combat'] ?? [];
        \assert(\is_array($combat));
        foreach (['enemies', 'bosses'] as $kind) {
            $enemies = $combat[$kind] ?? [];
            \assert(\is_array($enemies));
            foreach ($enemies as $enemy) {
                \assert(\is_array($enemy));
                \assert(\is_string($enemy['key']));
                \assert(\is_array($enemy['translations'] ?? null));
                /** @var array<string, array{name: string}> $entry */
                $entry = $enemy['translations'];
                $translations[$enemy['key']] = $entry;
            }
        }

        return $this->translations = $translations;
    }
}
