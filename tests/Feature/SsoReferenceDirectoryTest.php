<?php

use App\Models\Application;
use App\Models\Office;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = Application::factory()->create(['client_secret' => Hash::make('reference-test-secret')]);
    $this->headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => 'reference-test-secret'];
});

it('lists only active offices with separate abbreviations and resolves inactive historical IDs', function () {
    $office = Office::factory()->create(['name' => 'Municipal Engineering Office', 'abbreviation' => 'MEO', 'is_active' => true]);
    $retired = Office::factory()->create(['is_active' => false]);
    $expected = ['id' => $office->id, 'name' => $office->name, 'abbreviation' => 'MEO', 'is_active' => true];
    $response = $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk()->assertExactJson([
        'data' => [$expected], 'meta' => ['page' => 1, 'per_page' => 50, 'has_more' => false],
    ]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->getJson('/api/v1/sso/offices/'.$office->id, $this->headers)->assertExactJson(['data' => $expected]);
    $this->getJson('/api/v1/sso/offices/'.$retired->id, $this->headers)->assertOk()->assertJsonPath('data.is_active', false);
});

it('lists active positions and preserves inactive position lookups', function () {
    $position = Position::create(['title' => 'Engineer I', 'is_active' => true]);
    $retired = Position::create(['title' => 'Watchman', 'is_active' => false]);
    $this->getJson('/api/v1/sso/positions', $this->headers)->assertOk()->assertExactJson([
        'data' => [['id' => $position->id, 'title' => 'Engineer I', 'is_active' => true]],
        'meta' => ['page' => 1, 'per_page' => 50, 'has_more' => false],
    ]);
    $this->getJson('/api/v1/sso/positions/'.$retired->id, $this->headers)->assertOk()
        ->assertExactJson(['data' => ['id' => $retired->id, 'title' => 'Watchman', 'is_active' => false]]);
});

it('searches literal office names and abbreviations before stable pagination', function () {
    $first = Office::factory()->create(['name' => 'Municipal Test', 'abbreviation' => 'MTEST1']);
    $second = Office::factory()->create(['name' => 'Municipal Test Two', 'abbreviation' => 'MTEST2']);
    Office::factory()->create(['name' => 'Municipal Test Three', 'abbreviation' => 'MTEST3', 'is_active' => false]);
    Office::factory()->create(['name' => 'Unrelated', 'abbreviation' => 'NONE']);
    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson('/api/v1/sso/offices?'.http_build_query([
            'search' => ' MUNICIPAL mtest ', 'per_page' => 1, 'page' => $page,
        ]), $this->headers)->assertOk()->assertJsonCount($page < 3 ? 1 : 0, 'data');
        expect($response->json('meta.has_more'))->toBe($page === 1);
        if ($page < 3) {
            $response->assertJsonPath('data.0.id', $page === 1 ? $first->id : $second->id);
        }
    }
    foreach (['%', '_', '!', "' OR 1=1 --"] as $search) {
        $this->getJson('/api/v1/sso/offices?'.http_build_query(['search' => $search]), $this->headers)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    $literal = Office::factory()->create(['name' => 'Literal %_! Office']);
    $this->getJson('/api/v1/sso/offices?search=%25_!', $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
});

it('searches and paginates position titles', function () {
    $first = Position::create(['title' => 'Engineer I']);
    Position::create(['title' => 'Engineer II']);
    Position::create(['title' => 'Engineer III', 'is_active' => false]);
    Position::create(['title' => 'Clerk']);
    $this->getJson('/api/v1/sso/positions?search=ENGINEER&per_page=1', $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.has_more', true);
    $this->getJson('/api/v1/sso/positions?search=ENGINEER&per_page=1&page=2', $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
});

it('requires active application credentials on every reference route', function (string $path) {
    $url = '/api/v1/sso/'.$path;
    $response = $this->getJson($url)->assertUnauthorized();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($url, [...$this->headers, 'X-Client-Secret' => 'invalid'])->assertUnauthorized();
    $this->application->update(['is_active' => false]);
    $this->getJson($url, $this->headers)->assertUnauthorized();
})->with(['offices', 'positions', 'offices/1', 'positions/1']);

it('rejects invalid reference search and page bounds', function (array $query) {
    foreach (['offices', 'positions'] as $path) {
        $response = $this->getJson('/api/v1/sso/'.$path.'?'.http_build_query($query), $this->headers)->assertUnprocessable();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
})->with([
    [['search' => str_repeat('a', 101)]], [['search' => ['invalid']]],
    [['page' => 0]], [['page' => 10001]], [['page' => '1.5']],
    [['per_page' => 0]], [['per_page' => 101]],
]);

it('returns private not-found responses for absent reference IDs', function (string $path) {
    $response = $this->getJson('/api/v1/sso/'.$path.'/999999', $this->headers)->assertNotFound();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['offices', 'positions']);

it('shares the application rate limit across employee and reference directories', function () {
    $this->application->update(['rate_limit_per_minute' => 2]);
    $this->getJson('/api/v1/sso/directory?roles[]=standard', $this->headers)->assertOk();
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $response = $this->getJson('/api/v1/sso/positions', $this->headers)->assertStatus(429)->assertHeader('Retry-After');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
