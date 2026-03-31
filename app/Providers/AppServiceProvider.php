<?php

namespace App\Providers;

use App\Events\EmployeeCreated;
use App\Listeners\SendChatWebhook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(EmployeeCreated::class, SendChatWebhook::class);
    }
}
