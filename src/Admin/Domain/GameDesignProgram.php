<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une semaine civile répétée ; l'énergie décrit la journée entière, même sans séance.
 * Les plafonds bornent le laboratoire et ne modifient aucune règle de jeu.
 *
 * @phpstan-type SessionInput array{day: int, hour: int, minute: int, discipline: string, duration: int, distance: ?int, elevation: ?int}
 * @phpstan-type ProgramInput array{name: string, startDate: string, timezone: string, weeks: int, sessions: list<SessionInput>, energy: list<int>}
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_design_program')]
class GameDesignProgram
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(length: 80)] #[Assert\NotBlank] #[Assert\Length(max: 80)] private string $name = '';
    #[ORM\Column(length: 10)] #[Assert\Date] #[Assert\NotBlank] private string $startDate;
    #[ORM\Column(length: 80)] #[Assert\Timezone] private string $timezone = 'Europe/Paris';
    #[ORM\Column] #[Assert\Range(min: 1, max: 52)] private int $weeks = 4;
    /** @var list<SessionInput> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(max: 14)]
    #[Assert\All([new Assert\Collection(fields: [
        'day' => new Assert\Range(min: 1, max: 7),
        'hour' => new Assert\Range(min: 0, max: 23),
        'minute' => new Assert\Range(min: 0, max: 59),
        'discipline' => new Assert\Choice(callback: [self::class, 'disciplines']),
        'duration' => new Assert\Range(min: 1, max: 86400),
        'distance' => new Assert\Range(min: 0, max: 1000000),
        'elevation' => new Assert\Range(min: 0, max: 100000),
    ])])]
    private array $sessions = [];
    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(exactly: 7)]
    #[Assert\All([new Assert\Range(min: 0, max: 100000)])]
    private array $energy = [0, 0, 0, 0, 0, 0, 0];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->startDate = new DateTimeImmutable('monday this week')->format('Y-m-d');
    }

    /** @return list<string> */
    public static function disciplines(): array
    {
        return array_column(\App\Shared\Domain\Activity\Discipline::cases(), 'value');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function setStartDate(string $value): void
    {
        $this->startDate = $value;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $value): void
    {
        $this->timezone = $value;
    }

    public function getWeeks(): int
    {
        return $this->weeks;
    }

    public function setWeeks(int $value): void
    {
        $this->weeks = $value;
    }

    /** @return list<SessionInput> */
    public function getSessions(): array
    {
        return $this->sessions;
    }

    /** @param list<SessionInput> $value */
    public function setSessions(array $value): void
    {
        $this->sessions = array_values($value);
    }

    /** @return list<int> */
    public function getEnergy(): array
    {
        return $this->energy;
    }

    /** @param list<int> $value */
    public function setEnergy(array $value): void
    {
        $this->energy = array_values($value);
    }

    /** @return ProgramInput */
    public function input(): array
    {
        return ['name' => $this->name, 'startDate' => $this->startDate, 'timezone' => $this->timezone, 'weeks' => $this->weeks, 'sessions' => $this->sessions, 'energy' => $this->energy];
    }
}
