<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\FighterDerivation;
use App\Progression\Domain\LevelCurve;
use App\Rewards\Domain\ItemCatalog;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use InvalidArgumentException;

/**
 * L'équipement est fixé par le scénario, comme un inventaire déjà équipé. Les objets
 * désactivés restent résolubles comme dans ItemModifiers ; les clés absentes sont refusées.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 */
final class GameDesignInputs
{
    /** @param ProfileInput $profile
     * @return array{attributes: array<string, array{base: int, equipmentBonus: int, effective: int}>, fighter: \App\Combat\Domain\Fighter}
     */
    public static function fighter(array $profile, GameRulesets $rulesets, int $averageEnergy = 0, bool $allowOverride = true): array
    {
        /** @var array{combat: array{fighter: array<string, int>, formulas: array<string, array{source: string, combination: string, secondary: ?string}>}} $snapshot */
        $snapshot = $rulesets->snapshot();

        return FighterDerivation::resolve(self::progression($profile, $rulesets, $averageEnergy, $allowOverride), self::modifiers($profile['equipment'], $rulesets), CombatRules::fromSnapshot($snapshot['combat']['fighter']), $snapshot['combat']['formulas']);
    }

    /** @param ProfileInput $profile */
    public static function progression(array $profile, GameRulesets $rulesets, int $averageEnergy = 0, bool $allowOverride = true): PlayerProgression
    {
        $attributes = new AttributeGains($profile['strength'], $profile['endurance'], $profile['mobility'], $profile['dexterity']);
        foreach ($attributes->toArray() as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Les attributs du profil doivent être positifs ou nuls.');
            }
        }
        $standing = LevelCurve::runtime($rulesets)->standingAt($attributes->total());
        $vitality = Vitality::runtime($rulesets);
        $value = $allowOverride && null !== $profile['vitalityOverride'] ? $profile['vitalityOverride'] : $vitality->bonused($vitality->of($attributes), $averageEnergy);

        return new PlayerProgression($standing->level, $standing->xpIntoLevel, $standing->xpToNextLevel, null, $attributes, $value, $vitality->explain($averageEnergy));
    }

    /** @param list<string> $equipment
     * @return list<Modifier>
     */
    public static function modifiers(array $equipment, GameRulesets $rulesets): array
    {
        $catalog = ItemCatalog::runtime($rulesets);
        $slots = [];
        $modifiers = [];
        foreach ($equipment as $key) {
            $item = $catalog->find($key) ?? throw new InvalidArgumentException('Objet absent de cette version : '.$key);
            if (null === $item->slot || isset($slots[$item->slot->value])) {
                throw new InvalidArgumentException('Un seul équipement est permis par emplacement ; un coffre ne peut pas être équipé.');
            }
            $slots[$item->slot->value] = true;
            foreach ($item->modifiers as $modifier) {
                $modifiers[] = new Modifier($modifier->type, $modifier->value, ModifierSource::Item, $modifier->discipline);
            }
        }

        return $modifiers;
    }
}
