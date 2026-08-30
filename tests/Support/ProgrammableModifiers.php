<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\ModifierContributor;
use App\Shared\Domain\Modifier\Modifier;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Le contributeur *programmable* de l'environnement de test, pour les sources qui n'accordent
 * pas encore pour de vrai — le streak arrive au Lot 5, les compétences au Lot 7. `Rewards` a
 * cessé d'en faire partie au #29 : `ItemModifiers` contribue désormais réellement, à partir de
 * ce qu'un joueur a équipé. Les deux coexistent sans conflit dans `ModifierResolver` — un
 * compte de test n'a rien équipé tant qu'un test ne le fait pas lui-même via l'inventaire
 * réel, donc `ItemModifiers` y rend un ensemble vide, silencieusement, comme n'importe quel
 * autre contributeur sans rien à dire.
 *
 * Il existe pour prouver le **branchement** : que le tag posé sur `ModifierContributor`
 * est bien celui qu'attend `ModifierResolver`, et que ce qu'un module accorde traverse
 * jusqu'au ledger. C'est la seule chose qu'un test unitaire ne peut pas dire, et la panne
 * qu'elle attrape est silencieuse — un tag mal orthographié ne casse rien, il rend
 * simplement un ensemble vide, et le joueur est sous-payé sans que personne le voie.
 *
 * **L'état est statique**, à l'encontre de l'habitude. Deux raisons, et elles se cumulent :
 * le KernelBrowser redémarre le noyau entre deux requêtes HTTP, donc un état porté par
 * l'instance serait remis à zéro entre le test qui le programme et le service qui le lit ;
 * et le test n'a alors rien à aller chercher dans le conteneur, ce qui est heureux
 * puisqu'un service déclaré sous `when@test` n'existe pas dans le conteneur `dev` que
 * PHPStan analyse.
 *
 * Le prix est un état qui survit d'un test à l'autre — la suite tourne dans un seul
 * processus. {@see ApiTestCase::setUp()} le remet à zéro, au même titre que les tables : il
 * n'accorde rien par défaut, et les autres suites raisonnent donc sur le socle seul.
 */
final class ProgrammableModifiers implements ModifierContributor
{
    /** @var list<Modifier> */
    private static array $granted = [];

    /**
     * Le pendant daté de `$granted` — voir {@see grantFrom()}.
     *
     * @var array{from: DateTimeImmutable, modifiers: list<Modifier>}|null
     */
    private static ?array $dated = null;

    /** @see failAfter() */
    private static ?int $remainingBeforeFailure = null;

    public static function grant(Modifier ...$modifiers): void
    {
        self::$granted = array_values($modifiers);
    }

    /**
     * Accorde `$modifiers` **aux seules séances datées de `$from` ou après** — le cas d'une
     * source bornée dans le temps, la Risāla d'une guilde en premier (#191).
     *
     * C'est ce que le #190 rend démontrable, et rien d'autre ne le rend : sans un
     * contributeur qui regarde la date reçue, une signature datée reste une signature que
     * personne n'utilise, et le jour où un contributeur réel oublierait de s'en servir,
     * aucun test ne le verrait.
     */
    public static function grantFrom(DateTimeImmutable $from, Modifier ...$modifiers): void
    {
        self::$dated = ['from' => $from, 'modifiers' => array_values($modifiers)];
    }

    public static function grantNothing(): void
    {
        self::$granted = [];
        self::$dated = null;
        self::$remainingBeforeFailure = null;
    }

    /**
     * Fait échouer la résolution après `$successes` appels — c'est-à-dire **au milieu d'un
     * lot d'import**, une fois les premiers workouts écrits et crédités.
     *
     * C'est une panne injectée, et on préférerait une vraie contrainte : `RewardTransaction`
     * provoquait la sienne par `uniq_xp_transaction_source_reason`, la base refusant
     * elle-même un second crédit de la même séance. Aucune contrainte n'est atteignable ici —
     * la source d'une écriture est l'identifiant du workout, tiré par `Workout::record()`, et
     * aucun test ne peut le choisir pour le faire entrer en collision.
     *
     * Ce seam-là plutôt qu'un autre parce qu'il est déjà celui des tests, qu'il est dans la
     * transaction, et qu'il n'ajoute rien au code de production.
     *
     * **Deux appels par workout crédité depuis le #226**, et non un seul : `SessionRewards`
     * résout les modificateurs pour l'XP, `SessionDrops` les résout à nouveau pour
     * `LOOT_LUCK` — deux matières, deux résolutions, voir le docblock de `SessionDrops`.
     * `$successes` compte donc des **appels**, pas des workouts ; un test qui veut faire
     * échouer le lot après le premier workout entièrement traité programme `failAfter(2)`.
     */
    public static function failAfter(int $successes): void
    {
        self::$remainingBeforeFailure = $successes;
    }

    public function modifiersOf(Uuid $userId, DateTimeImmutable $occurredAt): array
    {
        if (null !== self::$remainingBeforeFailure && self::$remainingBeforeFailure-- <= 0) {
            throw new RuntimeException('Panne provoquée au milieu du lot.');
        }

        if (null === self::$dated || $occurredAt < self::$dated['from']) {
            return self::$granted;
        }

        return [...self::$granted, ...self::$dated['modifiers']];
    }
}
