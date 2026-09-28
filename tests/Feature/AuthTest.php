<?php

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});

it('can login with valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'employee' => ['uuid', 'email', 'first_name'],
        ]);
});

it('cannot login with invalid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized();
});

it('locks an account after five failed attempts and allows login after the window', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->employee->username,
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    $locked = $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ]);

    $locked->assertStatus(429)
        ->assertJsonPath('message', 'Too many login attempts. Please try again later.')
        ->assertJsonStructure(['retry_after']);
    expect((int) $locked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->toBe((int) $locked->json('retry_after'));

    $this->travel(16)->minutes();

    $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ])->assertSuccessful();
});

it('does not reveal whether a locked username exists', function () {
    $unknownUsername = 'missing.account';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'username' => $unknownUsername,
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    $this->postJson('/api/v1/auth/login', [
        'username' => $unknownUsername,
        'password' => 'wrong-password',
    ])->assertStatus(429)
        ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
});

it('limits failed attempts across usernames from one IP', function () {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'missing.'.$attempt,
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ])->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
        ->postJson('/api/v1/auth/login', [
            'username' => $this->employee->username,
            'password' => 'password',
        ])->assertSuccessful();
});

it('uses the forwarded client IP behind a trusted proxy for login limits', function () {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.10',
        ])->postJson('/api/v1/auth/login', [
            'username' => 'forwarded.missing.'.$attempt,
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '192.0.2.10',
    ])->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ])->assertStatus(429);

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '192.0.2.11',
    ])->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ])->assertSuccessful();
});

it('counts only failed logins and clears account failures after success', function () {
    for ($round = 0; $round < 2; $round++) {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => $this->employee->username,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'username' => $this->employee->username,
            'password' => 'password',
        ])->assertSuccessful();

        $this->travel(1)->seconds();
    }
});

it('cannot login with inactive account', function () {
    $this->employee->update(['is_active' => false]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $this->employee->username,
        'password' => 'password',
    ]);

    $response->assertUnauthorized();
});

it('can get authenticated employee profile', function () {
    $response = $this->asSsoEmployee($this->employee)
        ->getJson('/api/v1/auth/me');

    $response->assertSuccessful()
        ->assertJsonPath('data.email', 'test@example.com');
});

it('can logout', function () {
    $response = $this->asSsoEmployee($this->employee)
        ->postJson('/api/v1/auth/logout');

    $response->assertSuccessful()
        ->assertJsonPath('message', 'Successfully logged out.');
});

it('can logout from all sessions', function () {
    $response = $this->asSsoEmployee($this->employee)
        ->postJson('/api/v1/auth/logout-all');

    $response->assertSuccessful()
        ->assertJsonPath('message', 'Successfully logged out from all sessions.');
});

it('requires authentication for protected routes', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertUnauthorized();
});

it('does not allow public registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Public',
        'last_name' => 'User',
    ])->assertNotFound();
});
