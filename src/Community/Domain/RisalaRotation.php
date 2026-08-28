<?php

declare(strict_types=1);

namespace App\Community\Domain;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Qui envoie la Risāla de la semaine. **Domaine pur** : des identifiants entrent, un
 * identifiant sort — aucune base, aucune horloge, et le hasard lui-même entre par un
 * paramètre. C'est ce qui permet de tester la règle par table de cas plutôt que par
 * scénario, et de rejouer un tirage pour comprendre pourquoi il est tombé sur quelqu'un.
 *
 * ## La règle, en une phrase
 *
 *     tant que tout le monde n'a pas envoyé la sienne, on ne peut pas être tiré deux fois.
 *
 * Elle n'existe pas pour être équitable en moyenne — un tirage uniforme le serait déjà —
 * mais pour l'être **à court terme**. Une guilde de cinq personnes joue pendant des mois ;
 * un vrai hasard y produirait sans faute quelqu'un qui envoie trois fois avant que le
 * dernier ait envoyé une fois, et c'est celui-là qui décroche.
 *
 * ## Le cycle
 *
 * Un cycle est un tour complet. Quand plus personne n'est éligible, le suivant s'ouvre et
 * tout le monde redevient tirable : c'est le `+1` du constructeur, et c'est la seule façon
 * dont un cycle se termine. Le numéro est stocké sur chaque Risāla, donc « qui a déjà
 * envoyé » se relit sans jamais avoir à reconstituer un historique.
 *
 * **Un membre entré en cours de cycle est éligible tout de suite**, puisqu'il n'a envoyé
 * aucune Risāla de ce cycle-là. Il n'attend pas le cycle suivant, ce qui serait la plus
 * mauvaise façon d'accueillir quelqu'un.
 *
 * **Un membre parti garde ses envois passés** : les Risālāt qu'il a envoyées restent au
 * cycle, mais lui n'est plus dans `$members`, donc il ne pèse plus sur l'éligibilité. Un
 * cycle peut ainsi se terminer plus tôt que prévu, ce qui est le bon comportement.
 */
final readonly class RisalaRotation
{
    /**
     * Le cycle du tour à tirer : celui en cours, ou le suivant si tout le monde y a déjà
     * envoyé sa Risāla.
     */
    public int $cycle;

    /**
     * Les membres qui peuvent être tirés, **triés par identifiant**.
     *
     * Trié, parce que le tirage est audité : on stocke le rang tiré et la taille du vivier,
     * et rejouer un tirage exige de retrouver le même ordre. Un ordre rendu par la base
     * changerait au premier `VACUUM`, et la trace ne voudrait plus rien dire. L'UUID v7 est
     * croissant dans le temps, donc trier par identifiant range les membres par ancienneté
     * de compte — arbitraire, mais stable, et c'est tout ce qu'on demande.
     *
     * @var non-empty-list<Uuid>
     */
    public array $pool;

    /**
     * @param non-empty-list<Uuid> $members     les membres de la guilde, dans n'importe quel ordre
     * @param list<Uuid>           $alreadySent les expéditeurs du cycle `$currentCycle`
     */
    public function __construct(array $members, array $alreadySent, int $currentCycle)
    {
        $sent = array_flip(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $alreadySent));

        $eligible = array_values(array_filter($members, static fn (Uuid $id): bool => !isset($sent[$id->toRfc4122()])));

        // Personne d'éligible : le cycle est bouclé, le suivant s'ouvre et tout le monde
        // redevient tirable. C'est la seule façon dont un cycle se termine.
        if ([] === $eligible) {
            $this->cycle = $currentCycle + 1;
            $eligible = $members;
        } else {
            $this->cycle = $currentCycle;
        }

        usort($eligible, static fn (Uuid $left, Uuid $right): int => $left->toRfc4122() <=> $right->toRfc4122());

        $this->pool = $eligible;
    }

    /**
     * Celui que le rang `$roll` désigne. Le rang vient de l'appelant, qui seul a le droit
     * d'appeler un générateur aléatoire : le domaine reste rejouable, et la trace écrite sur
     * la Risāla (`drawRoll`, `drawPoolSize`) suffit à refaire le tirage à l'identique.
     *
     * C'est le même principe que pour le loot (#28) : un tirage serveur qui ne se raconte pas
     * ne se défend pas, et « pourquoi jamais moi ? » est une question qu'on nous posera.
     */
    public function drawnBy(int $roll): Uuid
    {
        if ($roll < 0 || $roll >= \count($this->pool)) {
            throw new InvalidArgumentException(\sprintf('Rang de tirage hors du vivier : %d pour %d éligible(s).', $roll, \count($this->pool)));
        }

        return $this->pool[$roll];
    }
}
