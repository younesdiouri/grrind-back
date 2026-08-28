<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * L'ensemble des modificateurs actifs d'un joueur, toutes sources confondues.
 *
 * **Un seul point d'agrégation, et c'est tout ce que fait cette classe.** C'est ce qui
 * empêche le moteur de pourrir : sans elle, chaque nouvelle source de bonus ajouterait une
 * branche dans le calcul d'XP, une autre dans le tirage de loot, et au bout de trois lots
 * plus personne ne saurait ce qui s'applique à qui. Ici, ouvrir une source est une classe
 * qui implémente {@see ModifierContributor} — rien à modifier ailleurs, ni ici.
 *
 * Elle ne compose rien : additionner, plafonner, filtrer par discipline sont des décisions
 * de consommateur, et elles diffèrent. `XpCalculator` groupe par source pour son détail
 * animé, le futur `LootRoller` ne lira que `LOOT_LUCK`. Un resolver qui trancherait pour
 * eux les obligerait à défaire son travail.
 *
 * **Aujourd'hui, aucune source ne contribue** : les compétences arrivent au Lot 7, le
 * streak au Lot 5, les objets au Lot 6. Le resolver rend donc un ensemble vide, et c'est
 * délibérément le branchement — pas le contenu — qui est éprouvé au Lot 3. C'est le seul
 * moment où on peut le faire sans qu'un test de bonus le masque.
 *
 * Il ne possède pas d'horloge non plus, et c'est le même refus (#190) : la date de la
 * séance traverse depuis l'appelant jusqu'aux contributeurs sans que personne en chemin
 * ait le droit d'en inventer une autre.
 */
final readonly class ModifierResolver
{
    /**
     * @param iterable<ModifierContributor> $contributors tous les modules qui accordent des effets, tagués par autoconfiguration
     */
    public function __construct(
        #[AutowireIterator(ModifierContributor::TAG)]
        private iterable $contributors,
    ) {
    }

    /**
     * **La sortie est ordonnée par source**, dans l'ordre de déclaration de
     * {@see ModifierSource}, et non dans celui où le conteneur a rangé les contributeurs.
     * Un ensemble résolu se retrouve dans un breakdown affiché et dans un tirage de loot
     * audité : le faire dépendre d'un ordre de compilation, c'est accepter que deux calculs
     * identiques laissent deux traces différentes. Le tri ne coûte rien, il y a quatre
     * sources.
     *
     * @param DateTimeImmutable $occurredAt la date **du sport** — voir {@see ModifierContributor::modifiersOf()}
     *
     * @return list<Modifier>
     */
    public function of(Uuid $userId, DateTimeImmutable $occurredAt): array
    {
        $contributed = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->modifiersOf($userId, $occurredAt) as $modifier) {
                $contributed[$modifier->source->value][] = $modifier;
            }
        }

        $resolved = [];

        foreach (ModifierSource::cases() as $source) {
            foreach ($contributed[$source->value] ?? [] as $modifier) {
                $resolved[] = $modifier;
            }
        }

        return $resolved;
    }
}
