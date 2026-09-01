<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Progression\Domain\XpTransaction;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class XpTransactionCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return XpTransaction::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('amount');
        yield TextField::new('reason');
        yield TextField::new('rulesetVersion');
    }
}
