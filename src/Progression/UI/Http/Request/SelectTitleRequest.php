<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Request;

use App\Progression\Domain\UnlockedTitle;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `{"titleId": null}` est une requête valide et non un oubli : c'est ainsi qu'on retire son
 * titre. D'où la valeur par défaut, et l'absence de `NotBlank`.
 *
 * La longueur est bornée sur celle de la colonne : au-delà, la base tronquerait ou
 * refuserait, et un 422 explicite vaut mieux qu'une erreur de pilote. Ce qui vaut ou non
 * comme identifiant, en revanche, est l'affaire du catalogue — le valider deux fois, ici et
 * là, ferait deux règles à garder d'accord.
 */
final readonly class SelectTitleRequest
{
    public function __construct(
        #[Assert\Length(max: UnlockedTitle::TITLE_ID_MAX_LENGTH)]
        public ?string $titleId = null,
    ) {
    }
}
