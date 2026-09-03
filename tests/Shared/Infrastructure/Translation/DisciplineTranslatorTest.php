<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use PHPUnit\Framework\TestCase;

final class DisciplineTranslatorTest extends TestCase
{
    public function testItUsesEnglishThenFrenchWhenTheRecipientsLocaleIsMissing(): void
    {
        $translator = new DisciplineTranslator(new class implements GameRulesets {
            public function snapshot(): array
            {
                return ['disciplines' => [
                    ['discipline' => 'RUNNING', 'translations' => ['en' => ['label' => 'Running'], 'fr' => ['label' => 'Course']]],
                    ['discipline' => 'WALKING', 'translations' => ['fr' => ['label' => 'Marche']]],
                ]];
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return 1;
            }
        });

        self::assertSame('Running', $translator->labelOf(Discipline::Running, 'de'));
        self::assertSame('Marche', $translator->labelOf(Discipline::Walking, 'de'));
    }
}
