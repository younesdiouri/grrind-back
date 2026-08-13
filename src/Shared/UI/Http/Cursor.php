<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Une position dans une liste ordonnée par date puis par identifiant.
 *
 * ————— Pourquoi ce n'est plus un simple UUID ————————————————————————————————————————————
 *
 * Les deux historiques paginaient sur l'UUID v7, triable par construction. Le raccourci
 * tenait tant que l'identifiant naissait à l'instant du fait : l'ordre des identifiants
 * était celui des dates. L'import a séparé les deux — dix workouts vieux de dix jours
 * reçoivent leurs identifiants à la file — et l'ordre rendu devenait celui de l'import et
 * non celui de la pratique.
 *
 * La date seule ne suffit pas non plus : deux workouts peuvent commencer à la même seconde,
 * et une page qui s'arrête entre les deux les rendrait deux fois ou pas du tout.
 * L'identifiant sert de **départage**, pas de tri principal.
 *
 * ————— Pourquoi c'est encodé et pas deux paramètres ——————————————————————————————————————
 *
 * Un curseur désigne une position, pas un couple de valeurs que le client aurait à
 * comprendre : le rendre opaque est ce qui permettra d'y ajouter un troisième critère sans
 * changer le contrat. C'est du base64url — pas du chiffrement : il n'y a rien de secret là
 * dedans, seulement rien à y lire.
 *
 * Il vit dans `Shared` parce que `Training` et `Progression` paginent tous les deux, et que
 * deux paginations qui divergeraient coûteraient deux implémentations côté client.
 */
final readonly class Cursor
{
    /** Le séparateur ne peut apparaître ni dans une date ATOM ni dans un UUID. */
    private const string SEPARATOR = '|';

    private function __construct(
        public DateTimeImmutable $at,
        public Uuid $id,
    ) {
    }

    public static function of(DateTimeImmutable $at, Uuid $id): self
    {
        return new self($at, $id);
    }

    /**
     * `null` sur une chaîne qui n'en est pas un — un curseur bricolé à la main est une
     * erreur de client, et l'appelant en fait un 422 qui nomme le paramètre.
     */
    public static function tryFrom(string $encoded): ?self
    {
        // Le remplissage est retiré à l'encodage — il n'apporte rien dans une URL — donc il
        // se remet ici plutôt que de compter sur l'indulgence du décodeur.
        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (4 * ceil(\strlen($encoded) / 4)), '=');
        $decoded = base64_decode($padded, true);

        if (false === $decoded || 2 !== \count($parts = explode(self::SEPARATOR, $decoded))) {
            return null;
        }

        [$at, $id] = $parts;

        if (!Uuid::isValid($id)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $at);

        return false === $parsed ? null : new self($parsed, Uuid::fromString($id));
    }

    /**
     * Le curseur d'une query string, ou un 422 qui nomme le paramètre.
     *
     * Le Serializer ne peut pas typer une chaîne opaque pour nous, donc c'est ici que le
     * refus se produit — et il prend exactement la forme que `#[MapQueryString]` aurait
     * donnée, pour que le client reçoive la même erreur que sur `limit` ou `discipline`.
     *
     * Un curseur illisible ne doit surtout pas être ignoré : une page vide ferait croire au
     * client qu'il est arrivé au bout de l'historique.
     *
     * @param object $query le DTO de requête, pour que la violation désigne le bon objet
     */
    public static function fromQuery(object $query, ?string $encoded): ?self
    {
        if (null === $encoded) {
            return null;
        }

        $cursor = self::tryFrom($encoded);

        if (null === $cursor) {
            throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation('Ce curseur est illisible. Renvoie le `nextCursor` de la page précédente, tel quel.', null, [], $query, 'cursor', $encoded)]));
        }

        return $cursor;
    }

    public function encoded(): string
    {
        return rtrim(strtr(base64_encode(
            $this->at->format(DateTimeInterface::ATOM).self::SEPARATOR.$this->id->toRfc4122(),
        ), '+/', '-_'), '=');
    }
}
