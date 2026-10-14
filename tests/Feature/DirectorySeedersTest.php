<?php

use App\Models\Office;
use App\Models\Position;
use Database\Seeders\OfficeSeeder;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports the office and position exports without merging distinct source records', function () {
    $this->seed([OfficeSeeder::class, PositionSeeder::class]);
    $this->seed([OfficeSeeder::class, PositionSeeder::class]);

    expect(Office::count())->toBe(86)
        ->and(Position::count())->toBe(306)
        ->and(Office::where('is_active', true)->count())->toBe(85)
        ->and(Position::where('is_active', true)->count())->toBe(303)
        ->and(Office::distinct()->count('name'))->toBe(86)
        ->and(Office::distinct()->count('abbreviation'))->toBe(86)
        ->and(Office::where('abbreviation', 'MMO-MPM')->value('name'))
        ->toBe("Municipal Mayor's Office - Municipal Project Monitoring")
        ->and(Office::where('abbreviation', 'MMO-PM')->value('name'))
        ->toBe("Municipal Mayor's Office - Project Monitoring")
        ->and(Office::where('abbreviation', 'MPSO')->value('name'))
        ->toBe('Municipal Public Safety Office')
        ->and(Office::where('abbreviation', 'MPSO-STANDALONE')->value('name'))
        ->toBe('Municipal Public Safety Office (standalone export entry)');
});

it('preserves existing IDs and employee references when renaming and retiring source records', function () {
    $legacy = [
        'MMO-GSD' => 'MAO-GSD', 'MMO-BPLD' => 'MAO-BPLD',
        'MMO-MPM-ALT' => 'MMO-PM', 'MHO-QHCI' => 'QHCI',
    ];
    $ids = [];
    foreach ($legacy as $old => $new) {
        $ids[$new] = Office::factory()->create(['abbreviation' => $old, 'name' => 'Legacy '.$old])->id;
    }
    $retiredOffice = Office::factory()->create(['abbreviation' => 'MPSO-STANDALONE', 'name' => 'Municipal Public Safety Office (standalone export entry)']);
    $retiredPosition = Position::create(['title' => 'Watchman', 'is_active' => true]);
    $employee = \App\Models\Employee::factory()->create([
        'office_id' => $retiredOffice->id, 'position_id' => $retiredPosition->id,
    ]);
    $legacyOffice = Office::factory()->create(['name' => 'Office omitted from source', 'abbreviation' => 'OLD-OFFICE']);
    $custom = Position::create(['title' => 'Locally managed position', 'is_active' => true]);
    $this->seed([OfficeSeeder::class, PositionSeeder::class]);
    $snapshot = Office::orderBy('id')->pluck('id', 'abbreviation')->all();
    $this->seed([OfficeSeeder::class, PositionSeeder::class]);
    expect(Office::orderBy('id')->pluck('id', 'abbreviation')->all())->toBe($snapshot);
    foreach ($ids as $abbreviation => $id) {
        expect(Office::where('abbreviation', $abbreviation)->value('id'))->toBe($id);
    }
    expect($retiredOffice->fresh()->is_active)->toBeFalse()
        ->and($retiredPosition->fresh()->is_active)->toBeFalse()
        ->and($employee->fresh()->office_id)->toBe($retiredOffice->id)
        ->and($employee->fresh()->position_id)->toBe($retiredPosition->id)
        ->and($custom->fresh()->is_active)->toBeFalse()
        ->and($legacyOffice->fresh()->is_active)->toBeFalse()
        ->and(Office::where('is_active', true)->count())->toBe(85)
        ->and(Position::where('is_active', true)->count())->toBe(303);
});

it('rolls back office updates when a rename would merge two existing identities', function () {
    $old = Office::factory()->create(['abbreviation' => 'MMO-GSD', 'name' => 'Old office identity']);
    $current = Office::factory()->create(['abbreviation' => 'MAO-GSD', 'name' => 'Conflicting office identity']);
    expect(fn () => $this->seed(OfficeSeeder::class))->toThrow(RuntimeException::class);
    expect(Office::count())->toBe(2)
        ->and($old->fresh()->abbreviation)->toBe('MMO-GSD')
        ->and($current->fresh()->abbreviation)->toBe('MAO-GSD');
});
