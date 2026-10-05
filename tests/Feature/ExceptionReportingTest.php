<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;

it('reports the same error at most 20 times a day', function (): void {
    $handler = resolve(ExceptionHandler::class);
    assert($handler instanceof Handler);

    $reported = 0;
    $handler->reportable(function (RuntimeException $e) use (&$reported): bool {
        $reported++;

        return false;
    });

    $sameError = fn (): RuntimeException => new RuntimeException('Boom');

    foreach (range(1, 25) as $attempt) {
        report($sameError());
    }

    expect($reported)->toBe(20);

    $this->travel(1)->day();
    report($sameError());

    expect($reported)->toBe(21);
});
