<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\CoinTransaction;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

final class CoinTransactionCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return CoinTransaction::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('amount');
        yield ChoiceField::new('reason')->setChoices([
            'Loot de séance' => CoinReason::WorkoutDrop,
            'Loot de combat' => CoinReason::BattleDrop,
            'Achat' => CoinReason::Purchase,
            'Coffre' => CoinReason::Chest,
        ]);
    }
}
