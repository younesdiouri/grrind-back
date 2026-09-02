<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleResult;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
        yield ChoiceField::new('result')->setChoices(['Victoire' => BattleResult::Victory, 'Défaite' => BattleResult::Defeat]);
        yield DateTimeField::new('foughtAt');
        yield TextField::new('rulesetVersion');
    }
}
