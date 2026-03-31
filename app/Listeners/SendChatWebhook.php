<?php

namespace App\Listeners;

use App\Events\EmployeeCreated;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendChatWebhook
{
    public function handle(EmployeeCreated $event): void
    {
        $url = config('webhooks.chat.url');
        $secret = config('webhooks.chat.secret');

        if (empty($url) || empty($secret)) {
            return;
        }

        $employee = $event->employee->load(['office', 'position']);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Webhook-Secret' => $secret,
                ])
                ->post($url, [
                    'event' => 'employee.created',
                    'employee' => [
                        'uuid' => $employee->uuid,
                        'username' => $employee->username,
                        'email' => $employee->email,
                        'first_name' => $employee->first_name,
                        'middle_name' => $employee->middle_name,
                        'last_name' => $employee->last_name,
                        'full_name' => $employee->full_name,
                        'position' => $employee->position?->title,
                        'office_name' => $employee->office?->name,
                        'is_active' => $employee->is_active,
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('[Webhook] Failed to notify chat: HTTP ' . $response->status());
            } else {
                Log::info('[Webhook] Chat sync triggered for new employee: ' . $employee->username);
            }
        } catch (\Exception $e) {
            Log::warning('[Webhook] Failed to notify chat: ' . $e->getMessage());
        }
    }
}
