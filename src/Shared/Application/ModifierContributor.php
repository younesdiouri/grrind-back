<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Modifier\Modifier;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un module a à dire sur les effets actifs d'un joueur. C'est le seul chemin par
 * lequel une source de bonus entre dans le moteur.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** Les
 * quatre sources de modificateurs vivent dans quatre modules — les compétences dans
 * `Progression`, les objets dans `Rewards`, le streak et la ligue dans `Engagement` — et
 * leurs deux consommateurs, `XpCalculator` et le futur `LootRoller`, vivent ailleurs
 * encore. Deptrac interdit les flèches entre modules métier, et c'est heureux : sans ce
 * port, calculer l'XP demanderait d'importer une entité de chacun des trois autres.
 *
 * L'événement de domaine, l'autre chemin autorisé, ne convient pas ici : la réponse est
 * nécessaire tout de suite, au milieu d'une transaction verrouillée. Une copie répliquée
 * en asynchrone créditerait un joueur sur des bonus périmés — exactement l'écueil déjà
 * écarté pour {@see PlayerTimezones}.
 *
 * **Ce port est le seul en éventail** : plusieurs implémentations, un seul consommateur —
 * {@see ModifierResolver}, qui les agrège. Le branchement est du Symfony standard, tag
 * posé par autoconfiguration sur l'interface et injecté en `AutowireIterator` côté
 * resolver : un module qui ouvre une source n'a rien à câbler, et surtout personne à
 * prévenir. https://symfony.com/doc/current/service_container/tags.html
 *
 * Le contrat est volontairement minuscule — un UUID entre, des modificateurs sortent. Pas
 * de discipline en paramètre : un modificateur porte sa propre portée, et c'est
 * {@see Modifier::appliesTo()} qui tranche, chez le consommateur qui connaît son contexte.
 */
#[AutoconfigureTag(ModifierContributor::TAG)]
interface ModifierContributor
{
    /** Répété dans l'attribut ci-dessus : `self::` n'y est pas résoluble. */
    public const string TAG = 'app.modifier_contributor';

    /**
     * Les modificateurs que ce module accorde au joueur, ici et maintenant.
     *
     * Un ensemble vide est une réponse normale — un joueur sans série, sans objet équipé
     * ni compétence n'est pas une anomalie. Une panne, en revanche, se propage : un
     * contributeur qui échoue doit faire échouer la transaction plutôt que de laisser
     * écrire au ledger un montant amputé d'un bonus dû, qui ne se corrigerait plus.
     *
     * @return list<Modifier>
     */
    public function modifiersOf(Uuid $userId): array;
}
