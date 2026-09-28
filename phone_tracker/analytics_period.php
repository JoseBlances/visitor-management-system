<?php

/**
 * Resolves an analytics period (today, week, month, quarter) to its start time and "now".
 * Call date_default_timezone_set() before this so the boundaries match the local clock.
 *
 * @return array{0: string, 1: DateTimeImmutable, 2: DateTimeImmutable} period, start, now
 */
function analytics_period_range(string $requested): array
{
    $allowedPeriods = ["today", "week", "month", "quarter"];
    $period = strtolower(trim($requested));
    if (!in_array($period, $allowedPeriods, true)) {
        $period = "month";
    }

    $now = new DateTimeImmutable("now");
    switch ($period) {
        case "today":
            $start = $now->setTime(0, 0, 0);
            break;
        case "week":
            $start = $now->modify("monday this week")->setTime(0, 0, 0);
            break;
        case "quarter":
            $quarterMonth = ((int) floor(((int) $now->format("n") - 1) / 3) * 3) + 1;
            $start = $now->setDate((int) $now->format("Y"), $quarterMonth, 1)->setTime(0, 0, 0);
            break;
        case "month":
        default:
            $start = $now->modify("first day of this month")->setTime(0, 0, 0);
            break;
    }

    return [$period, $start, $now];
}
