<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Translation;

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
final readonly class EnemyTranslator
{
    /** Le domaine de traduction, donc le nom des fichiers : `translations/enemies.<locale>.yaml`. */
    public const string DOMAIN = 'enemies';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param string $key la clé du catalogue (`Enemy::$key`) — pas nécessairement encore
     *                    présente dans `EnemyCatalog` : un combat déjà joué la porte dans
     *                    son snapshot indépendamment du catalogue courant
     */
    public function nameOf(string $key): string
    {
        return $this->translator->trans(strtolower($key).'.name', domain: self::DOMAIN);
    }
}
