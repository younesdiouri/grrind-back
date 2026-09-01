<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Combat\Domain\Battle;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class BattleCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return Battle::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('enemySnapshot.key')->setLabel('Ennemi');
        yield TextField::new('result');
        yield DateTimeField::new('foughtAt');
        yield TextField::new('rulesetVersion');
    }
}
