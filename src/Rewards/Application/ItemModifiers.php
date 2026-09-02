<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\ModifierContributor;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Les objets équipés, traduits en {@see Modifier} — **le premier contributeur réel du
 * produit** (#29). `ModifierResolver` rendait un ensemble vide depuis le Lot 3 ; c'est cette
 * classe qui l'éprouve pour de bon, du YAML jusqu'au calcul d'XP et jusqu'au `Fighter`.
 *
 * **Rien à câbler.** `implements ModifierContributor` suffit : le tag `ModifierContributor::TAG`
 * est posé par autoconfiguration sur l'interface, `ModifierResolver` l'injecte déjà en
 * `AutowireIterator` — voir le docblock du port. Aucun fichier de service à toucher, personne
 * à prévenir dans `Progression` ni dans `Combat`.
 *
 * ## `$occurredAt` est ignoré, et c'est délibéré
 *
 * Le port l'autorise explicitement : « un contributeur dont les effets ne sont pas bornés
 * dans le temps — un nœud de compétence débloqué, **un objet équipé** — peut ignorer le
 * paramètre » (voir le docblock de {@see ModifierContributor::modifiersOf()}). Un objet reste
 * équipé jusqu'à ce qu'on le retire ; contrairement à la guilde (#190), il n'a pas de fenêtre
 * de validité à comparer à la date du sport. Le paramètre reste dans la signature pour que
 * le port serve aussi la guilde, pas parce que cette classe en aurait besoin.
 *
 * ## L'invariant central : cette classe ne crédite rien
 *
 * Elle traduit un état déjà écrit — l'emplacement porté d'une {@see \App\Rewards\Domain\InventoryItem}
 * — en une liste de {@see Modifier} que le **consommateur** (`XpCalculator`, `LootRoller`,
 * `FighterFactory`) applique à son propre calcul. Elle n'écrit jamais dans `Progression`, ne
 * touche à aucun ledger, ne recalcule aucun total. `GET /api/progression` continue de rendre
 * le socle gagné par le sport, exactement comme avant ce ticket : un équipement qui gonflerait
 * une jauge rendrait illisible ce qu'une séance a rapporté, et ferait bouger la `Vitality` —
 * une mesure de l'équilibre d'une *pratique* — au gré d'une paire de bottes. Si ce
 * raisonnement semble un jour «se corriger» en faisant écrire cette classe quelque part,
 * c'est le raisonnement qui a raison, pas la correction.
 *
 * ## La composition de plusieurs `STREAK_SHIELD` n'est pas tranchée ici
 *
 * le snapshot publié pose déjà un objet à une seule charge et renvoie la question à ce ticket.
 * Cette classe ne la tranche pas : elle rend les charges **telles quelles**, une par objet
 * équipé qui en porte, sans les sommer ni les dédupliquer — exactement comme elle le ferait
 * pour n'importe quel autre type. Sommer plusieurs boucliers, ou n'en compter qu'un, est une
 * décision de game design qui dépend d'une mécanique de série qui n'existe pas encore
 * (`Engagement`, Lot 5) ; elle appartient à son consommateur, pas à ce contributeur.
 */
final readonly class ItemModifiers implements ModifierContributor
{
    public function __construct(
        private InventoryItemRepository $inventory,
        private ItemCatalog $catalog,
    ) {
    }

    /** @return list<Modifier> */
    public function modifiersOf(Uuid $userId, DateTimeImmutable $occurredAt): array
    {
        $modifiers = [];

        foreach ($this->inventory->equippedByPlayer($userId) as $equipped) {
            $item = $this->catalog->find($equipped->itemKey())
                ?? throw new LogicException(\sprintf('"%s" est équipé par un joueur mais n\'existe plus dans le catalogue.', $equipped->itemKey()));

            foreach ($item->modifiers as $itemModifier) {
                $modifiers[] = new Modifier($itemModifier->type, $itemModifier->value, ModifierSource::Item, $itemModifier->discipline);
            }
        }

        return $modifiers;
    }
}
