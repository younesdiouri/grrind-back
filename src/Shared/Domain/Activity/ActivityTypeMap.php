<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
use InvalidArgumentException;

/**
 * Traduit ce que la montre a enregistré en {@see Discipline}.
 *
 * Objet typé et immuable, construit une fois à la compilation du conteneur depuis
 * `config/game/v1/activity_types.yaml` — c'est là que vivent les valeurs et leur
 * justification. Il porte les règles de cohérence de la table, et
 * `ActivityTypesSection` les lui fait dire plutôt que de les réécrire.
 *
 * **La traduction ne va que dans un sens.** Plusieurs types du fournisseur pointent la
 * même discipline, et l'inverse n'aurait aucun sens : personne ne convertit une
 * discipline Grrind en type Apple.
 *
 * **Un type inconnu rend `null`, il ne lève pas.** Un fournisseur qui ajoute une activité
 * n'est pas une panne du serveur : c'est un workout qu'on n'importe pas, et que l'import
 * doit compter et nommer au joueur (#92). Lever ici ferait échouer un lot entier pour une
 * séance de curling.
 */
final class ActivityTypeMap
{
    use RuntimeRuleset;
    private const string APPLE_HEALTH = 'APPLE_HEALTH';
    private const string HEALTH_CONNECT = 'HEALTH_CONNECT';

    /** @var array<string, array<string, Discipline>> source → type du fournisseur → discipline */
    private array $bySource;

    /**
     * `$appleHealth` et `$healthConnect` sont pris séparément et non dans une table
     * indexée par source, parce que les deux espaces de noms sont disjoints mais rien ne
     * garantit qu'ils le restent : les garder séparés évite qu'un type Google écrase un
     * jour un type Apple sans que personne ne le voie.
     *
     * @param list<array{activity_type: string, discipline: string}> $appleHealth
     * @param list<array{activity_type: string, discipline: string}> $healthConnect
     */
    public function __construct(array $appleHealth, array $healthConnect, ?GameRulesets $rulesets = null)
    {
        $this->useRuntimeRulesets($rulesets);
        $this->bySource = [
            self::APPLE_HEALTH => self::index($appleHealth, self::APPLE_HEALTH),
            self::HEALTH_CONNECT => self::index($healthConnect, self::HEALTH_CONNECT),
        ];

        // Une discipline qu'aucun type ne produit est une discipline qu'aucun workout ne
        // portera jamais : elle apparaîtrait dans le contrat, dans le barème d'XP et dans
        // les titres, sans qu'un joueur puisse l'atteindre. On préfère ne pas démarrer.
        foreach (self::sources() as $source) {
            foreach (Discipline::cases() as $discipline) {
                if (!\in_array($discipline, $this->bySource[$source], true)) {
                    throw new InvalidArgumentException(\sprintf('Aucun type "%s" ne mène à la discipline "%s".', $source, $discipline->value));
                }
            }
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    /**
     * Les sources d'import qui portent une table. Ce sont les valeurs que
     * `WorkoutSource` prendra au #87 ; elles sont ici en chaînes parce que l'enum
     * n'existe pas encore et que le créer serait voler son ticket au suivant.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        return [self::APPLE_HEALTH, self::HEALTH_CONNECT];
    }

    /**
     * `null` quand la source ou le type est inconnu — les deux cas se traitent pareil,
     * le workout n'entre pas.
     */
    public function disciplineFor(string $source, string $activityType): ?Discipline
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->disciplineFor($source, $activityType);
        }

        return $this->bySource[$source][$activityType] ?? null;
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        $bySource = [self::APPLE_HEALTH => [], self::HEALTH_CONNECT => []];
        /** @var list<array{source: string, provider_type: string, discipline: string, active: bool}> $activityTypes */
        $activityTypes = $snapshot['activity_types'];
        foreach ($activityTypes as $activityType) {
            if ($activityType['active']) {
                $bySource[$activityType['source']][] = ['activity_type' => $activityType['provider_type'], 'discipline' => $activityType['discipline']];
            }
        }

        return new self($bySource[self::APPLE_HEALTH], $bySource[self::HEALTH_CONNECT], $rulesets);
    }

    /**
     * @param list<array{activity_type: string, discipline: string}> $mappings
     *
     * @return array<string, Discipline>
     */
    private static function index(array $mappings, string $source): array
    {
        $indexed = [];

        foreach ($mappings as $mapping) {
            $type = $mapping['activity_type'];

            // Un doublon n'est jamais anodin : soit les deux lignes disent la même chose
            // et l'une est du bruit, soit elles se contredisent et la dernière gagne en
            // silence. Les deux méritent d'être corrigés avant le démarrage.
            if (isset($indexed[$type])) {
                throw new InvalidArgumentException(\sprintf('Type "%s" déclaré deux fois pour la source "%s".', $type, $source));
            }

            $indexed[$type] = Discipline::tryFrom($mapping['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue pour le type "%s" de "%s" : "%s".', $type, $source, $mapping['discipline']));
        }

        return $indexed;
    }
}
