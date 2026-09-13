<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Form\StatFormulaType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class StatFormulaFormTest extends KernelTestCase
{
    public function testAnInvalidCombinationIsAnInlineFormError(): void
    {
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(StatFormulaType::class, null, ['csrf_protection' => false]);
        $form->submit(['source' => 'strength', 'combination' => 'sum', 'secondary' => 'strength']);
        self::assertFalse($form->isValid());
        self::assertStringContainsString('distincts', (string) $form->getErrors(true));
        $valid = $factory->create(StatFormulaType::class, null, ['csrf_protection' => false]);
        $valid->submit(['source' => 'dexterity', 'combination' => 'arithmetic_mean', 'secondary' => 'mobility']);
        self::assertTrue($valid->isValid());
    }
}
