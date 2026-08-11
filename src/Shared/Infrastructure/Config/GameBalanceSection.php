<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Le schéma d'un fichier d'équilibrage de `config/game/v1/`. Un fichier, une section,
 * un schéma — et le schéma vit dans le module qui lit la section, pas ici : `Shared` ne
 * connaît aucun module, c'est ce qui le garde partageable.
 *
 * Le schéma est un `TreeBuilder` du composant Config, pas un parseur maison : il décrit
 * les clés attendues, leurs types et leurs bornes, et le composant refuse tout le reste —
 * y compris une clé en trop, qui est le vrai piège de l'équilibrage (une faute de frappe
 * dans un nom de réglage passerait inaperçue et la valeur par défaut s'appliquerait en
 * silence).
 *
 * **Une règle de cohérence entre plusieurs clés se délègue à l'objet du domaine qui la
 * porte** plutôt que d'être réécrite ici : deux formulations de la même règle finissent
 * toujours par diverger. Voir `App\Training\Infrastructure\Config\TrainingSection`.
 *
 * Ajouter une section, c'est : le fichier YAML, la classe de schéma dans son module, et
 * une ligne dans `App\Kernel::build()`. Un fichier posé dans le dossier sans schéma fait
 * échouer la compilation du conteneur — sinon ce serait du réglage qui ne s'applique pas.
 */
interface GameBalanceSection extends ConfigurationInterface
{
    /**
     * Le fichier décrit, extension comprise, relatif à `config/game/v1/`. Son nom sans
     * extension nomme la section, donc le préfixe des paramètres produits :
     * `training.yaml` donne `game.training.*`.
     */
    public function file(): string;
}
