<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Les colonnes JSON ici sont des objets de formulaire fixes, non une `CollectionType`.
 *
 * EasyAdmin infère sinon `ArrayField` depuis Doctrine JSON et lui ajoute `allow_add` /
 * `entry_type`, options que nos types composés refusent. Ce petit champ concret évite
 * l'inférence tout en laissant Symfony construire le vrai FormType structuré.
 */
final class StructuredField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, TranslatableInterface|string|bool|null $label = null): self
    {
        return new self()
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplateName('crud/field/text');
    }
}
