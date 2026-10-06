<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

class SupportRealtime
{
    public function publicConfiguration(): array
    {
        return [
            'key' => config('services.pusher.key'),
            'cluster' => config('services.pusher.cluster'),
            'enabled' => $this->configured(),
        ];
    }

    public function configured(): bool
    {
        return filled(config('services.pusher.app_id'))
            && filled(config('services.pusher.key'))
            && filled(config('services.pusher.secret'))
            && filled(config('services.pusher.cluster'));
    }

    public function authorize(string $channel, string $socketId): array
    {
        return json_decode($this->client()->authorizeChannel($channel, $socketId), true, flags: JSON_THROW_ON_ERROR);
    }

    public function signal(int $conversationId, int $customerId): void
    {
        if (! $this->configured()) {
            return;
        }

        try {
            $this->client()->trigger([
                'private-support-team',
                "private-support-customer-{$customerId}",
            ], 'support.changed', ['conversation_id' => $conversationId]);
        } catch (Throwable $exception) {
            Log::warning('Support realtime signal failed.', ['conversation_id' => $conversationId, 'error' => $exception->getMessage()]);
        }
    }

    private function client(): Pusher
    {
        return new Pusher(
            (string) config('services.pusher.key'),
            (string) config('services.pusher.secret'),
            (string) config('services.pusher.app_id'),
            ['cluster' => (string) config('services.pusher.cluster'), 'useTLS' => true],
        );
    }
}
