<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fige l'équilibrage dans le conteneur, à la compilation.
 *
 * **C'est le seul moment où le YAML est lu.** Le conteneur compilé porte ensuite des
 * paramètres scalaires ; en mode worker FrankenPHP, aucune requête ne rouvre un fichier,
 * et un YAML incohérent fait échouer le `cache:warmup` du build plutôt que la première
 * requête d'un joueur.
 *
 * Le pass **aplatit** la configuration validée en paramètres nommés — `training.yaml`
 * donne `game.training.minimum_duration_seconds` — parce que c'est ce qu'un argument de
 * service sait consommer : `services.yaml` injecte des entiers dans `TrainingRules`, et
 * un paramètre absent casse la compilation en nommant le service fautif. Le domaine ne
 * voit jamais un tableau d'équilibrage.
 *
 * @see https://symfony.com/doc/current/service_container/compiler_passes.html
 */
final readonly class GameBalancePass implements CompilerPassInterface
{
    /** @var list<GameBalanceSection> */
    private array $sections;

    public function __construct(private string $directory, GameBalanceSection ...$sections)
    {
        $this->sections = array_values($sections);
    }

    public function process(ContainerBuilder $container): void
    {
        // Le dossier entier, et pas seulement les fichiers connus : c'est ce qui fait
        // recompiler le conteneur quand un fichier d'équilibrage *apparaît*, donc ce qui
        // permet à la garde contre les fichiers sans schéma de se déclencher en dev.
        // Sans effet en prod, où le conteneur est figé et les ressources non suivies.
        $container->addResource(new DirectoryResource($this->directory, '/\.yaml$/'));

        $balance = new GameBalanceLoader($this->directory)->load(...$this->sections);

        // La version de l'équilibrage se stocke avec chaque transaction d'XP : c'est elle
        // qui permet de rééquilibrer sans corrompre l'historique. Disponible partout par
        // le bind `string $rulesetVersion` de services.yaml.
        $container->setParameter('game.ruleset_version', $balance->version);

        foreach ($balance->sections as $section => $values) {
            $this->expose($container, 'game.'.$section, $values);
        }
    }

    /**
     * On descend dans les tables de clés, pas dans les listes : `minimum_duration_seconds`
     * est un réglage et mérite son paramètre, alors que les paliers d'une courbe de niveaux
     * sont une valeur unique qui se transmet d'un bloc.
     *
     * @param array<string, mixed> $values
     */
    private function expose(ContainerBuilder $container, string $prefix, array $values): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;

            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                /** @var array<string, mixed> $value */
                $this->expose($container, $path, $value);

                continue;
            }

            // Le composant Config ne rend que ça — il n'y a pas d'objet dans un YAML
            // validé. L'assertion est là pour le dire au lecteur autant qu'à l'analyse.
            \assert(null === $value || \is_scalar($value) || \is_array($value));

            $container->setParameter($path, $value);
        }
    }
}
