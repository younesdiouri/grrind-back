<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Translation;

use App\Combat\Domain\Enemy;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Met un nom sur un ennemi — même geste que
 * {@see \App\Progression\Infrastructure\Translation\TitleTranslator} pour les titres.
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

    public function nameOf(Enemy $enemy): string
    {
        return $this->translator->trans(strtolower($enemy->key).'.name', domain: self::DOMAIN);
    }
}
