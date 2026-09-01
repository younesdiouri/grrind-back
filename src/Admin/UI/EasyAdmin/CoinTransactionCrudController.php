<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Rewards\Domain\CoinTransaction;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class CoinTransactionCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return CoinTransaction::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('amount');
        yield TextField::new('reason');
    }
}
