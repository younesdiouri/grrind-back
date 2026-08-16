<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Community\Domain\Guild;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Renommer n'a qu'un champ, et il est obligatoire : un `PATCH` qui n'enverrait rien
 * n'aurait aucun sens ici, contrairement à `PATCH /api/me` où plusieurs attributs
 * coexistent et où l'absence veut dire « ne touche pas ».
 */
final readonly class RenameGuildRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Guild::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name = '',
    ) {
    }
}
