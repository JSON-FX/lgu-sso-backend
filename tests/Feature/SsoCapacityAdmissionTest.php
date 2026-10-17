<?php

use App\Support\SsoCapacityException;
use App\Support\SsoWorkBudget;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('atomically charges rejected reservations and keeps scopes independent', function () {
    $budget = app(SsoWorkBudget::class);
    $budget->charge('first', 2, 3);
    try {
        $budget->charge('first', 2, 3);
        $this->fail('Over-budget work admitted.');
    } catch (SsoCapacityException $exception) {
        expect($exception->status)->toBe(429)->and($exception->retryAfter)->toBeGreaterThan(0);
    }
    $key = 'sso-work:v1:first:'.intdiv(now()->timestamp, 60);
    expect($budget->store()->get($key))->toBe(4);
    $budget->charge('second', 3, 3);
});

it('retains old counter generations after a fixed-window boundary', function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(59));
    $budget = app(SsoWorkBudget::class);
    $key = 'sso-work:v1:boundary:'.intdiv(now()->timestamp, 60);
    $budget->charge('boundary', 1, 1);
    $this->travel(2)->seconds();
    $budget->charge('boundary', 1, 1);
    expect($budget->store()->get($key))->toBe(1)->and(DB::table('cache')->count())->toBe(2);
});

it('reserves the fresh window once and retains both charges after rollover', function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(59));
    $original = Cache::store('database');
    $old = 'sso-work:v1:delayed:'.intdiv(now()->timestamp, 60);
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('add')->twice()->andReturnUsing($original->add(...));
    $calls = 0;
    $repository->shouldReceive('increment')->twice()->andReturnUsing(function ($key, $amount) use ($original, &$calls) {
        $used = $original->increment($key, $amount);
        if (++$calls === 1) {
            $this->travel(2)->seconds();
        }

        return $used;
    });
    Cache::shouldReceive('store')->andReturn($repository);
    app(SsoWorkBudget::class)->charge('delayed', 1, 10);
    $new = 'sso-work:v1:delayed:'.intdiv(now()->timestamp, 60);
    expect($old)->not->toBe($new);
    expect($original->get($old))->toBe(1)->and($original->get($new))->toBe(1);
});

it('does not coerce failed or malformed atomic increments into admitted work', function ($value) {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('add')->andReturn(true);
    $repository->shouldReceive('increment')->andReturn($value);
    Cache::shouldReceive('store')->andReturn($repository);
    try {
        app(SsoWorkBudget::class)->charge('failure', 1, 10);
        $this->fail('Failed increment passed.');
    } catch (SsoCapacityException $exception) {
        expect($exception->status)->toBe(503);
    }
})->with([false, 0, '1', 1.0, null]);

it('rejects unsupported and unguarded alternate cache connections', function () {
    config(['cache.stores.database.connection' => 'different']);
    expect(fn () => app(SsoWorkBudget::class)->store())->toThrow(SsoCapacityException::class);
    config(['cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => 'different']);
    expect(fn () => app(SsoWorkBudget::class)->store())->toThrow(SsoCapacityException::class);
    config(['cache.stores.database.lock_connection' => null, 'sso.capacity_store' => 'array']);
    expect(fn () => app(SsoWorkBudget::class)->store())->toThrow(SsoCapacityException::class);
});

it('ignores exhausted expired reservations but enforces the current generation', function (int $fresh, int $expected) {
    $this->travelTo(now()->startOfMinute()->addSeconds(59));
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('add')->twice()->andReturn(false);
    $calls = 0;
    $repository->shouldReceive('increment')->twice()->andReturnUsing(function () use (&$calls, $fresh) {
        if (++$calls === 1) {
            $this->travel(2)->seconds();

            return 11;
        }

        return $fresh;
    });
    Cache::shouldReceive('store')->andReturn($repository);
    try {
        app(SsoWorkBudget::class)->charge('exhausted', 1, 10);
        expect($expected)->toBe(200);
    } catch (SsoCapacityException $exception) {
        expect($exception->status)->toBe($expected);
        expect($exception->retryAfter)->toBe(59);
    }
})->with([[1, 200], [11, 429]]);

it('bounds repeated clock rollover to two charged attempts and fails unavailable', function () {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('add')->twice()->andReturn(true);
    $repository->shouldReceive('increment')->twice()->andReturnUsing(function () {
        $this->travel(61)->seconds();

        return 1;
    });
    Cache::shouldReceive('store')->andReturn($repository);
    try {
        app(SsoWorkBudget::class)->charge('moving-clock', 1, 10);
        $this->fail('Unstable admission passed.');
    } catch (SsoCapacityException $exception) {
        expect($exception->status)->toBe(503);
    }
});

it('fails closed if the fresh-window increment is invalid or unavailable', function (string $failure) {
    $this->travelTo(now()->startOfMinute()->addSeconds(59));
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('add')->twice()->andReturn(true);
    $calls = 0;
    $repository->shouldReceive('increment')->twice()->andReturnUsing(function () use (&$calls, $failure) {
        if (++$calls === 1) {
            $this->travel(2)->seconds();

            return 1;
        }
        if ($failure === 'exception') {
            throw new RuntimeException('unavailable');
        }

        return false;
    });
    Cache::shouldReceive('store')->andReturn($repository);
    try {
        app(SsoWorkBudget::class)->charge('failed-retry', 1, 10);
        $this->fail('Failed admission passed.');
    } catch (SsoCapacityException $exception) {
        expect($exception->status)->toBe(503);
    }
})->with(['false', 'exception']);
