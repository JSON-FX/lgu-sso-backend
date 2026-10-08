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
        ->and(Office::distinct()->count('name'))->toBe(86)
        ->and(Office::distinct()->count('abbreviation'))->toBe(86)
        ->and(Office::where('abbreviation', 'MMO-MPM')->value('name'))
        ->toBe("Municipal Mayor's Office - Municipal Project Monitoring")
        ->and(Office::where('abbreviation', 'MMO-MPM-ALT')->value('name'))
        ->toBe('Municipal Project Monitoring (standalone export entry)')
        ->and(Office::where('abbreviation', 'MPSO')->value('name'))
        ->toBe('Municipal Public Safety Office')
        ->and(Office::where('abbreviation', 'MPSO-STANDALONE')->value('name'))
        ->toBe('Municipal Public Safety Office (standalone export entry)');
});
