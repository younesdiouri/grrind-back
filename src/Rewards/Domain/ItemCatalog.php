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
 * négatif, une clé vide. Cinq règles échappent à ça et vivent ici, même raison que
 * {@see \App\Combat\Domain\EnemyCatalog} : une clé d'objet dupliquée, un modificateur dont le
 * type ou la discipline ne correspondent à rien de connu, une discipline posée sur un type de
 * combat (#29), et — depuis le #229 — un `minimum_level` de boutique posé sans `available:
 * true`, et un objet EPIC ou LEGENDARY listé à l'étal. `ModifierType::tryFrom()` plutôt qu'une
 * énumération recopiée dans le schéma : c'est ce qui laisse le #224 ouvrir de nouveaux types
 * sans toucher à cette classe, voir le docblock d'{@see ItemModifier}.
 *
 * ## Une discipline sur un modificateur de combat se refuse au chargement (#29)
 *
 * `FighterFactory` traite les neuf types de combat ouverts au #224 (`STRENGTH_BONUS`,
 * `ENDURANCE_BONUS`, `MOBILITY_BONUS`, `DEXTERITY_BONUS`, `HP_BONUS`, `DAMAGE_BONUS`,
 * `MITIGATION_BONUS`, `EXTRA_TURN_BONUS`, `DODGE_BONUS`) comme globaux, sans jamais regarder
 * {@see \App\Shared\Domain\Modifier\Modifier::$discipline} — voir son docblock : « un combat
 * n'a lieu dans aucune discipline ». Écrire `{ type: STRENGTH_BONUS, value: 350, discipline:
 * RUNNING }` dans `items.yaml` produirait donc un objet qui s'applique **partout**, alors que
 * le fichier prétend le contraire de ce que le moteur fait. Une config qui ment se refuse au
 * démarrage plutôt que de se documenter : voir {@see self::COMBAT_MODIFIER_TYPES} et le refus
 * dans {@see modifiers()}.
 *
 * ## La boutique lit `shop:`, et deux mensonges de config s'y refusent (#229)
 *
 * `shop: { available: true, minimum_level: N }` est facultatif — absent vaut « pas vendu »,
 * voir `GET /api/shop`. Deux incohérences s'y refusent au démarrage plutôt qu'à l'exécution,
 * voir {@see shopListing()} :
 *
 *   - un `minimum_level` posé alors que `available` n'est pas `true` : un verrou de niveau
 *     pour un objet qui prétend ne pas être vendu ne veut rien dire ;
 *   - un objet EPIC ou LEGENDARY listé à l'étal : ces deux raretés ne se vendent jamais — un
 *     objet qui s'achète n'est plus une récompense de tirage, et si les meilleurs objets
 *     s'achètent, le loot ne récompense plus rien. `items.yaml` n'en pose aucun aujourd'hui ;
 *     ce refus protège la décision plutôt que de compter sur ce qu'un futur contributeur se
 *     souvienne de la prose du fichier.
 */
final readonly class ItemCatalog
{
    /**
     * Les neuf types de combat du #224 — voir le docblock de la classe pour pourquoi aucun
     * n'accepte de discipline. Recopiée ici plutôt que déléguée à une méthode de
     * `ModifierType` : le refus est une règle du catalogue, pas du vocabulaire partagé, et
     * `ItemsSection`/`ItemCatalog` sont explicitement les deux classes que le ticket #29 en
     * charge.
     *
     * @var list<ModifierType>
     */
    private const array COMBAT_MODIFIER_TYPES = [
        ModifierType::StrengthBonus,
        ModifierType::EnduranceBonus,
        ModifierType::MobilityBonus,
        ModifierType::DexterityBonus,
        ModifierType::HpBonus,
        ModifierType::DamageBonus,
        ModifierType::MitigationBonus,
        ModifierType::ExtraTurnBonus,
        ModifierType::DodgeBonus,
    ];

    /** @var array<string, Item> */
    private array $byKey;

    /**
     * @param list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>, shop?: array{available?: bool, minimum_level?: int}}> $items
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

            $shop = self::shopListing($entry['key'], $rarity, $entry['shop'] ?? null);

            $item = new Item(
                $entry['key'],
                $rarity,
                $slot,
                $entry['price_coins'],
                self::modifiers($entry['key'], $entry['modifiers']),
                $shop['available'],
                $shop['minimumLevel'],
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
     * L'étal de `GET /api/shop` (#229) : les objets dont `shop.available` vaut `true`, dans
     * l'ordre de déclaration — même geste que {@see all()}, un sous-ensemble plutôt qu'un tri
     * qui recomposerait un ordre.
     *
     * @return list<Item>
     */
    public function shopItems(): array
    {
        return array_values(array_filter($this->byKey, static fn (Item $item): bool => $item->shopAvailable));
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

                if (null !== $discipline && \in_array($type, self::COMBAT_MODIFIER_TYPES, true)) {
                    throw new InvalidArgumentException(\sprintf('"%s" porte un modificateur de combat "%s" avec une discipline ("%s") : un combat n\'a lieu dans aucune discipline, voir le docblock de FighterFactory.', $itemKey, $type->value, $discipline->value));
                }

                return new ItemModifier($type, $modifier['value'], $discipline);
            },
            $modifiers,
        );
    }

    /**
     * Résout et vérifie le bloc `shop:` d'une entrée — voir « La boutique lit `shop:` » dans
     * le docblock de la classe pour les deux refus.
     *
     * @param array{available?: bool, minimum_level?: int}|null $shop
     *
     * @return array{available: bool, minimumLevel: int}
     */
    private static function shopListing(string $itemKey, Rarity $rarity, ?array $shop): array
    {
        $available = $shop['available'] ?? false;
        $minimumLevel = $shop['minimum_level'] ?? null;

        if (null !== $minimumLevel && !$available) {
            throw new InvalidArgumentException(\sprintf('"%s" porte un minimum_level de boutique sans être à l\'étal ("available: true" manquant) : une config qui ment se refuse au démarrage.', $itemKey));
        }

        if ($available && \in_array($rarity, [Rarity::Epic, Rarity::Legendary], true)) {
            throw new InvalidArgumentException(\sprintf('"%s" est %s : les objets EPIC et LEGENDARY ne sont jamais vendus, voir le docblock de la classe.', $itemKey, $rarity->value));
        }

        return ['available' => $available, 'minimumLevel' => $minimumLevel ?? 1];
    }
}
