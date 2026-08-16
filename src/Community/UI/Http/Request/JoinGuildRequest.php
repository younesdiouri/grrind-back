<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Community\Domain\GuildInviteCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le code, tel que le joueur l'a tapé ou collé.
 *
 * **La normalisation se fait dans le constructeur, et c'est ce qui la rend fiable.** Le
 * `MapRequestPayload` désérialise d'abord, valide ensuite : la casse et les espaces sont
 * donc déjà corrigés quand les contraintes s'appliquent, et un code collé depuis un
 * message — en minuscules, avec une espace au bout — passe au lieu d'être refusé pour une
 * raison que le joueur ne peut pas comprendre. La normaliser dans le contrôleur laisserait
 * la validation juger la chaîne brute, et c'est exactement le bug que le test a trouvé.
 *
 * En majuscules et non en minuscules : c'est l'alphabet du code, donc la comparaison SQL
 * reste exacte et l'index utilisable — pas de `LOWER()` sur chaque recherche.
 *
 * La longueur exacte est contrainte ici et pas seulement en base : un code de trois
 * caractères n'a aucune chance d'exister, et le laisser passer consommerait un jeton du
 * limiteur pour une requête qui ne pouvait rien apprendre.
 */
final readonly class JoinGuildRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(exactly: GuildInviteCode::LENGTH)]
    #[Assert\Regex(pattern: '/^[A-Z0-9]+$/', message: 'Un code d\'invitation ne contient que des lettres majuscules et des chiffres.')]
    public string $code;

    public function __construct(string $code = '')
    {
        $this->code = strtoupper(trim($code));
    }
}
