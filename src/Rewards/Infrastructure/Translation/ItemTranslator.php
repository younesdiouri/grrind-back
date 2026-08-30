<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Translation;

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
final readonly class ItemTranslator
{
    /** Le domaine de traduction, donc le nom des fichiers : `translations/items.<locale>.yaml`. */
    public const string DOMAIN = 'items';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function nameOf(string $key): string
    {
        return $this->translator->trans(strtolower($key).'.name', domain: self::DOMAIN);
    }
}
