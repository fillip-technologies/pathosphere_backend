<?php

namespace App\Modules\Locker\Jobs;

use App\Modules\Locker\Contracts\Abdm\HipCallback;
use App\Modules\Locker\Services\HipCallbackHandler;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Acts on one verified ABDM callback. ABDM expects a quick 202 and our
 * answer as a separate call, so the work happens here. Encrypted on the
 * queue: callbacks carry names, phones, ABHA numbers and link codes.
 */
final class ProcessHipCallback implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly HipCallback $callback) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // A callback names a facility or a request of ours, not an organization up front.
        return [new WithSystemScope(null)];
    }

    public function handle(HipCallbackHandler $handler): void
    {
        $handler->handle($this->callback);
    }
}
