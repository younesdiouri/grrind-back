<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use InvalidArgumentException;

/**
 * Un titre : un identifiant et ce qu'il faut faire pour le porter, et **rien d'autre**.
 *
 * Pas de libellé ici, et c'est le point : les mots vivent dans `translations/titles.*.yaml`,
 * pas dans l'équilibrage. Un titre est une récompense de statut, donc il ne porte non plus
 * aucun modificateur — le jour où l'un d'eux donnerait un bonus, il cesserait d'être un
 * titre et deviendrait un objet équipé, avec les emplacements et l'arbitrage que ça suppose.
 */
final readonly class Title
{
    /**
     * L'identifiant est **le préfixe de ses clés de traduction** (`veteran.name`,
     * `veteran.hint`) et part au client tel quel. D'où la forme contrainte : un espace ou
     * une majuscule casserait silencieusement la résolution du libellé, et le joueur lirait
     * la clé brute.
     */
    public function __construct(
        public string $id,
        public TitleCondition $condition,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new InvalidArgumentException(\sprintf('Identifiant de titre invalide : "%s". Attendu : minuscules, chiffres et tirets bas.', $id));
        }
    }
}
