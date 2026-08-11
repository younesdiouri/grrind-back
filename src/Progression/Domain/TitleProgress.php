<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Où en est un joueur sur un titre donné. C'est ce que le client affiche : un libellé, une
 * barre, une unité.
 *
 * `current` est **écrêté à la cible** : une barre ne dépasse pas, et un joueur qui a couru
 * 300 séances n'a pas besoin qu'on lui dise qu'il en fallait 100. La comparaison de
 * déblocage, elle, se fait sur la valeur réelle — c'est `TitleCondition::isMetBy()` qui la
 * tranche, jamais ce qui est affiché.
 */
final readonly class TitleProgress
{
    public int $current;
    public int $target;
    public bool $isMet;

    public function __construct(public Title $title, int $current)
    {
        $this->target = $title->condition->threshold;
        $this->isMet = $current >= $this->target;
        $this->current = min(max(0, $current), $this->target);
    }

    public static function of(Title $title, PlayerRecord $record): self
    {
        return new self($title, $title->condition->progressOf($record));
    }

    public function unit(): ProgressUnit
    {
        return $this->title->condition->requirement->unit();
    }

    /**
     * Lequel des deux est le plus près d'aboutir, en proportion du chemin à parcourir.
     *
     * Le produit en croix plutôt qu'une division : les unités ne se comparent pas — 40 000
     * XP sur 50 000 est plus proche que 8 séances sur 25 — et seul le *ratio* les met sur
     * la même échelle. En entiers, parce qu'un flottant introduirait des ex æquo qui n'en
     * sont pas et rendrait l'ordre dépendant de l'arrondi.
     *
     * Le chemin se mesure depuis l'origine de la condition et non depuis zéro : voir
     * {@see TitleRequirement::origin()}.
     */
    public function isCloserThan(self $other): bool
    {
        return $this->advance() * $other->span() > $other->advance() * $this->span();
    }

    /** Ce qui a été parcouru depuis l'origine. */
    private function advance(): int
    {
        return max(0, $this->current - $this->title->condition->requirement->origin());
    }

    /** Ce qu'il y avait à parcourir. Au moins 1 : une distance nulle ne se divise pas. */
    private function span(): int
    {
        return max(1, $this->target - $this->title->condition->requirement->origin());
    }
}
