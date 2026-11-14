<?php

declare(strict_types=1);

namespace IndianOpinion\PromptLibrary\Tests\Support;

use IndianOpinion\PromptLibrary\Tests\Stubs\State;
use IndianOpinion\PromptLibrary\WordPress\Clock;

final class FrozenClock extends Clock
{
    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . State::$now))->setTimezone(new \DateTimeZone('UTC'));
    }
}
