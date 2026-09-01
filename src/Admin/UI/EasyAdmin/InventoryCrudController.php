<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Rewards\Domain\InventoryItem;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class InventoryCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return InventoryItem::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('itemKey');
        yield IntegerField::new('quantity');
        yield TextField::new('equippedSlot');
    }
}
