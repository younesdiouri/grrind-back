<?php

declare(strict_types=1);

namespace App\Shared\Domain\Alam;

use DateTimeImmutable;
use DateTimeZone;

/** Les semaines sont civiles : modifier de sept jours préserve 19/20 h aux changements DST. */
final readonly class AlamCalendar
{
    public function __construct(private AlamRules $rules)
    {
    }

    /** @return array{start: DateTimeImmutable, close: DateTimeImmutable, reset: DateTimeImmutable} */
    public function week(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new DateTimeZone($this->rules->text('timezone')));
        $sunday = $local->modify('sunday this week')->setTime($this->rules->integer('reset_hour'), 0);
        $start = $sunday <= $local ? $sunday : $sunday->modify('-7 days');
        $reset = $start->modify('+7 days');
        $utc = new DateTimeZone('UTC');

        return ['start' => $start->setTimezone($utc), 'close' => $reset->setTime($this->rules->integer('start_hour'), 0)->setTimezone($utc), 'reset' => $reset->setTimezone($utc)];
    }

    public function retainedSeconds(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end <= $start) {
            return 0;
        }
        $retained = $end->getTimestamp() - $start->getTimestamp();
        $window = $this->week($start);
        while ($window['close'] < $end) {
            $retained -= max(0, min($end->getTimestamp(), $window['reset']->getTimestamp()) - max($start->getTimestamp(), $window['close']->getTimestamp()));
            $window = $this->week($window['reset']);
        }

        return max(0, $retained);
    }
}
