<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetPublisher;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Une référence publiée active ne peut pas survivre à la désactivation de sa cible. */
final class ActiveGameConfigurationValidationTest extends KernelTestCase
{
    public function testActiveAdversaryTableCannotOutliveItsEnemy(): void
    {
        $snapshot = $this->snapshot();
        /** @var list<array{key: string}> $tables */
        $tables = $snapshot['loot']['adversary'];
        self::assertNotEmpty($tables);
        $key = $tables[0]['key'];
        /** @var list<array<string, mixed>> $enemies */
        $enemies = $snapshot['combat']['enemies'];
        foreach ($enemies as &$enemy) {
            if ($enemy['key'] === $key) {
                $enemy['active'] = false;
                break;
            }
        }
        unset($enemy);
        $snapshot['combat']['enemies'] = $enemies;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('La table adversaire active "%s" doit référencer un adversaire actif.', $key));
        $this->validate($snapshot);
    }

    public function testActiveChestTableCannotOutliveItsChest(): void
    {
        $snapshot = $this->snapshot();
        /** @var list<array{key: string}> $tables */
        $tables = $snapshot['loot']['chest'];
        self::assertNotEmpty($tables);
        $key = $tables[0]['key'];
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        foreach ($items as &$item) {
            if ($item['key'] === $key) {
                $item['active'] = false;
                break;
            }
        }
        unset($item);
        $snapshot['items'] = $items;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('La table coffre active "%s" doit référencer un coffre actif.', $key));
        $this->validate($snapshot);
    }

    public function testTrainingMinimumMustRemainStrictlyBelowTheMaximum(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['training']['maximum_duration_seconds'] = $snapshot['training']['minimum_duration_seconds'];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('strictement sous le plafond');
        $this->validate($snapshot);
    }

    public function testVitalityWindowMustCoverAtLeastOneDay(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['attributes']['vitality']['window_days'] = 0;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('fenêtre de Vitality');
        $this->validate($snapshot);
    }

    #[DataProvider('notificationWindowKeys')]
    public function testNotificationWindowsMustRemainPositive(string $key): void
    {
        $snapshot = $this->snapshot();
        $snapshot['notifications'][$key] = 0;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('notification');
        $this->validate($snapshot);
    }

    /** @return iterable<string, array{string}> */
    public static function notificationWindowKeys(): iterable
    {
        yield 'fraîcheur' => ['freshness_window_minutes'];
        yield 'délai' => ['announcement_delay_seconds'];
        yield 'abandon' => ['stale_window_minutes'];
    }

    public function testActiveActivityTypeCannotTargetAnInactiveDiscipline(): void
    {
        $snapshot = $this->snapshot();
        $discipline = $snapshot['activity_types'][0]['discipline'];
        foreach ($snapshot['disciplines'] as &$row) {
            if ($row['discipline'] === $discipline) {
                $row['active'] = false;
                break;
            }
        }
        unset($row);
        foreach ($snapshot['titles'] as &$title) {
            if (($title['condition']['discipline'] ?? null) === $discipline) {
                $title['active'] = false;
            }
        }
        unset($title);
        foreach ($snapshot['loot']['workout'] as &$table) {
            $table['eligibility']['disciplines'] = array_values(array_filter(
                $table['eligibility']['disciplines'],
                static fn (string $candidate): bool => $candidate !== $discipline,
            ));
        }
        unset($table);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('type d’activité actif');
        $this->validate($snapshot);
    }

    /** @return array{items: list<array<string, mixed>>, titles: list<array{active: bool, condition: array{discipline?: string|null}}&array<string, mixed>>, combat: array{enemies: list<array<string, mixed>>, bosses: list<array<string, mixed>>, fighter: array<string, mixed>}, loot: array{adversary: list<array{key: string}>, chest: list<array{key: string}>, workout: list<array{eligibility: array{disciplines: list<string>}}>} & array<string, mixed>, training: array{minimum_duration_seconds: int, maximum_duration_seconds: int}, attributes: array{vitality: array{window_days: int}}, notifications: array<string, int>, activity_types: list<array{discipline: string} & array<string, mixed>>, disciplines: list<array{discipline: string, active: bool}>} */
    private function snapshot(): array
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);

        /** @var array{items: list<array<string, mixed>>, titles: list<array{active: bool, condition: array{discipline?: string|null}}&array<string, mixed>>, combat: array{enemies: list<array<string, mixed>>, bosses: list<array<string, mixed>>, fighter: array<string, mixed>}, loot: array{adversary: list<array{key: string}>, chest: list<array{key: string}>, workout: list<array{eligibility: array{disciplines: list<string>}}>} & array<string, mixed>, training: array{minimum_duration_seconds: int, maximum_duration_seconds: int}, attributes: array{vitality: array{window_days: int}}, notifications: array<string, int>, activity_types: list<array{discipline: string} & array<string, mixed>>, disciplines: list<array{discipline: string, active: bool}>} $snapshot */
        $snapshot = $ruleset->snapshot();

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function validate(array $snapshot): void
    {
        $method = new ReflectionMethod(GameRulesetPublisher::class, 'validate');
        $method->invoke(null, $snapshot);
    }
}
