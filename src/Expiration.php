<?php

declare(strict_types=1);

namespace SitePreview;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Expiration
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function format(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format('Y-m-d\TH:i');
    }

    public function parse(string $local): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $this->timezone);
        // Also reject nonexistent wall-clock times during the spring DST change.
        if ($date === false || $date->format('Y-m-d\TH:i') !== $local) {
            throw new InvalidArgumentException('Enter a valid date and time in ' . $this->timezone->getName() . '.');
        }
        return $date->getTimestamp();
    }
}
