<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Translation;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use LogicException;
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

    public function testItSkipsEmptyLabelsWhenFallingBack(): void
    {
        $translator = new DisciplineTranslator($this->rulesets(['en' => ['label' => ''], 'fr' => ['label' => 'Course']]));

        self::assertSame('Course', $translator->labelOf(Discipline::Running, 'en'));
    }

    public function testItRefusesAMissingLabelInsteadOfExposingTheEnumValue(): void
    {
        $translator = new DisciplineTranslator($this->rulesets(['en' => ['label' => ''], 'fr' => ['label' => '']]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RUNNING');
        $translator->labelOf(Discipline::Running, 'en');
    }

    public function testItReloadsLabelsWhenTheVersionChangesAtTheSameRevision(): void
    {
        $rulesets = new class implements GameRulesets {
            public string $label = 'Running';
            public string $publishedVersion = 'v1-before-reset';

            public function snapshot(): array
            {
                return ['disciplines' => [['discipline' => 'RUNNING', 'translations' => ['en' => ['label' => $this->label]]]]];
            }

            public function version(): string
            {
                return $this->publishedVersion;
            }

            public function revision(): int
            {
                return 1;
            }
        };
        $translator = new DisciplineTranslator($rulesets);
        self::assertSame('Running', $translator->labelOf(Discipline::Running, 'en'));

        $rulesets->label = 'New running';
        $rulesets->publishedVersion = 'v1-after-reset';

        self::assertSame('New running', $translator->labelOf(Discipline::Running, 'en'));
    }

    /** @param array<string, array{label: string}> $translations */
    private function rulesets(array $translations): GameRulesets
    {
        return new class($translations) implements GameRulesets {
            /** @param array<string, array{label: string}> $translations */
            public function __construct(private readonly array $translations)
            {
            }

            public function snapshot(): array
            {
                return ['disciplines' => [['discipline' => 'RUNNING', 'translations' => $this->translations]]];
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return 1;
            }
        };
    }
}
