<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/State.php';
require __DIR__ . '/classes.php';
require __DIR__ . '/wpdb.php';
require __DIR__ . '/functions.php';
require dirname(__DIR__, 2) . '/src/functions.php';

\IndianOpinion\PromptLibrary\Tests\Stubs\State::reset();
