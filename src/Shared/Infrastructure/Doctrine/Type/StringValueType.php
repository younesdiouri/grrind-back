<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\StringValue;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

/**
 * Persiste n'importe quel `StringValue` en colonne texte. Chaque VO n'a plus qu'à
 * déclarer sa classe — on évite un type Doctrine complet par value object.
 *
 * Une valeur illisible en base lève : mieux vaut une erreur bruyante qu'un VO
 * fabriqué de travers qui se propage dans le moteur de jeu.
 */
abstract class StringValueType extends Type
{
    /**
     * @param array<string, mixed> $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $class = $this->valueClass();

        if (!$value instanceof $class) {
            throw InvalidType::new($value, static::class, ['null', $class]);
        }

        return $value->toString();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringValue
    {
        if (null === $value || $value instanceof StringValue) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, static::class, ['null', 'string']);
        }

        try {
            return $this->valueClass()::fromString($value);
        } catch (InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, static::class, $e->getMessage(), $e);
        }
    }

    /**
     * @return class-string<StringValue>
     */
    abstract protected function valueClass(): string;
}
