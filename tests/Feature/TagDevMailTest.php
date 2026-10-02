<?php

use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/**
 * @return list<string>
 */
function sentSubjects(): array
{
    /** @var ArrayTransport $transport */
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport->messages()->map(fn (SentMessage $sent): string => $sent->getOriginalMessage()->getSubject())->values()->all();
}

it('prefixes mail subjects with [DEV] on the dev stack', function (): void {
    app()->instance('env', 'staging');

    Mail::raw('Hello', fn (Message $message) => $message->to('a@example.com')->subject('Your code'));

    expect(sentSubjects())->toBe(['[DEV] Your code']);
});

it('leaves production subjects alone', function (): void {
    app()->instance('env', 'production');

    Mail::raw('Hello', fn (Message $message) => $message->to('a@example.com')->subject('Your code'));

    expect(sentSubjects())->toBe(['Your code']);
});
