<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\ModifierType;
use InvalidArgumentException;

/**
 * Le catalogue des objets, chargé depuis `config/game/v1/items.yaml`.
 *
 * **Un catalogue, pas une table.** Les objets ne vivent pas en base — même geste que
 * {@see \App\Combat\Domain\EnemyCatalog} pour les ennemis, {@see
 * \App\Progression\Domain\TitleCatalog} pour les titres : ajouter un objet est un
 * déploiement, pas un INSERT.
 *
 * Rien ici ne parle d'inventaire ni de tirage — ce ticket (#27) pose la matière que le
 * tirage (#28) et l'inventaire (#29) consomment. Un `Item` de ce catalogue n'appartient à
 * personne ; le devenir est une ligne d'inventaire séparée.
 *
 * ## Ce que le schéma ne peut pas dire, et que ce constructeur dit à sa place
 *
 * `ItemsSection` ne borne que ce qu'un `TreeBuilder` sait borner par champ — un prix
 * négatif, une clé vide. Deux règles échappent à ça et vivent ici, même raison que
 * {@see \App\Combat\Domain\EnemyCatalog} : une clé d'objet dupliquée, et un modificateur
 * dont le type ou la discipline ne correspondent à rien de connu. `ModifierType::tryFrom()`
 * plutôt qu'une énumération recopiée dans le schéma : c'est ce qui laisse le #224 ouvrir de
 * nouveaux types sans toucher à cette classe, voir le docblock d'{@see ItemModifier}.
 */
final readonly class ItemCatalog
{
    /** @var array<string, Item> */
    private array $byKey;

    /**
     * @param list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items
     *
     * @throws InvalidArgumentException le catalogue ne tient pas debout ; la compilation du conteneur s'arrête là
     */
    public function __construct(array $items)
    {
        // Un catalogue vide ne propose aucune récompense : mieux vaut refuser de démarrer
        // que de laisser le #28 n'avoir rien à tirer.
        if ([] === $items) {
            throw new InvalidArgumentException('Un catalogue d\'objets vide ne propose aucune récompense.');
        }

        $byKey = [];

        foreach ($items as $entry) {
            $rarity = Rarity::tryFrom($entry['rarity'])
                ?? throw new InvalidArgumentException(\sprintf('Rareté inconnue pour "%s" : "%s".', $entry['key'], $entry['rarity']));

            $slot = EquipmentSlot::tryFrom($entry['slot'])
                ?? throw new InvalidArgumentException(\sprintf('Emplacement d\'équipement inconnu pour "%s" : "%s".', $entry['key'], $entry['slot']));

            $item = new Item(
                $entry['key'],
                $rarity,
                $slot,
                $entry['price_coins'],
                self::modifiers($entry['key'], $entry['modifiers']),
            );

            if (isset($byKey[$item->key])) {
                throw new InvalidArgumentException(\sprintf('Deux objets pour la clé "%s".', $item->key));
            }

            $byKey[$item->key] = $item;
        }

        $this->byKey = $byKey;
    }

    public function find(string $key): ?Item
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * Le catalogue entier, dans l'ordre de déclaration — ce que le test de couverture des
     * traductions parcourt pour vérifier qu'aucun objet n'est livré sans nom, même geste
     * que `EnemyCatalog::all()`.
     *
     * @return list<Item>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /**
     * @param list<array{type: string, value: int, discipline?: string}> $modifiers
     *
     * @return list<ItemModifier>
     */
    private static function modifiers(string $itemKey, array $modifiers): array
    {
        return array_map(
            static function (array $modifier) use ($itemKey): ItemModifier {
                $type = ModifierType::tryFrom($modifier['type'])
                    ?? throw new InvalidArgumentException(\sprintf('"%s" porte un modificateur de type inconnu : "%s".', $itemKey, $modifier['type']));

                $discipline = isset($modifier['discipline'])
                    ? Discipline::tryFrom($modifier['discipline'])
                        ?? throw new InvalidArgumentException(\sprintf('"%s" porte un modificateur pour une discipline inconnue : "%s".', $itemKey, $modifier['discipline'])) : null;

                return new ItemModifier($type, $modifier['value'], $discipline);
            },
            $modifiers,
        );
    }
}
