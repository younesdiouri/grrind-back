<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Ce qu'un titre demande pour se débloquer.
 *
 * Vocabulaire **fermé**, et court volontairement : chaque valeur ajoutée est une donnée que
 * l'évaluation doit savoir lire à la complétion d'une séance, sans requête supplémentaire et
 * sans franchir une frontière de module. Les quatre valeurs ci-dessous se lisent toutes du
 * ledger d'XP et du snapshot — donc de ce que `Progression` possède déjà.
 *
 * Les conditions de streak et de classement viendront quand `Engagement` existera (#24,
 * #35). Les écrire maintenant reviendrait à coder contre une table absente.
 *
 * Les valeurs sont celles écrites dans le snapshot de jeu publié : les changer
 * invaliderait le catalogue livré.
 */
enum TitleRequirement: string
{
    /** Le niveau projeté par la courbe. La discipline n'a pas de sens ici. */
    case LevelReached = 'level_reached';

    /** L'XP cumulée au ledger, toutes disciplines confondues. */
    case TotalXp = 'total_xp';

    /** Le nombre de séances créditées — toutes, ou celles d'une seule discipline. */
    case SessionCount = 'session_count';

    /** Le temps cumulé dans une discipline, en secondes. La discipline est obligatoire. */
    case DisciplineSeconds = 'discipline_seconds';

    /**
     * Une condition qui *doit* nommer sa discipline : « cumuler 100 heures » sans dire de
     * quoi mélangerait le temps de course et celui de mobilité, qui ne se comparent pas.
     */
    public function needsDiscipline(): bool
    {
        return self::DisciplineSeconds === $this;
    }

    /**
     * Une condition qui *peut* la nommer. `session_count` est la seule des quatre à avoir
     * un sens dans les deux portées : « 100 séances » et « 20 séances de nage » sont deux
     * titres différents et tous les deux souhaitables.
     */
    public function acceptsDiscipline(): bool
    {
        return $this->needsDiscipline() || self::SessionCount === $this;
    }

    /**
     * D'où part un joueur qui n'a **rien** fait.
     *
     * Zéro partout, sauf pour le niveau : la courbe commence à 1, donc un joueur tout neuf
     * est déjà « niveau 1 sur 5 ». Sans cette origine, une condition de niveau paraîtrait
     * entamée à 20 % dès l'inscription et raflerait le titre « prochain à viser » à une
     * condition réellement à une séance d'aboutir. C'est la seule chose que l'origine
     * corrige — elle sert au classement, pas à l'affichage, qui continue de dire « niveau 1
     * sur 5 » parce que c'est ce que le joueur lit sur son écran.
     */
    public function origin(): int
    {
        return self::LevelReached === $this ? 1 : 0;
    }

    /**
     * Dans quelle unité se comptent la progression et le seuil. Elle part au client : sans
     * elle, `43 200 / 360 000` ne veut rien dire, et le client n'a pas à déduire l'unité du
     * type de condition — ce serait la même règle écrite deux fois, des deux côtés du
     * réseau.
     */
    public function unit(): ProgressUnit
    {
        return match ($this) {
            self::LevelReached => ProgressUnit::Levels,
            self::TotalXp => ProgressUnit::Xp,
            self::SessionCount => ProgressUnit::Sessions,
            self::DisciplineSeconds => ProgressUnit::Seconds,
        };
    }
}
