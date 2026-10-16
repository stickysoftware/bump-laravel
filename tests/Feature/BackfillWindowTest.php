<?php

// A BackfillWindow bounds a backfill query: since inclusive, until exclusive, in the app timezone.

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StickySoftware\Bump\Backfill\BackfillWindow;
use StickySoftware\Bump\Tests\Fixtures\User;

function window(string $until, ?string $since = null): BackfillWindow
{
    return new BackfillWindow(CarbonImmutable::parse($until), $since === null ? null : CarbonImmutable::parse($since));
}

it('bounds a query below until, and from since when set', function () {
    $query = window('2026-10-01 00:00:00', '2026-09-01 00:00:00')->constrain(DB::table('orders'), 'paid_at');

    expect($query->toSql())->toBe('select * from "orders" where "paid_at" < ? and "paid_at" >= ?')
        ->and(DB::connection()->prepareBindings($query->getBindings()))->toBe(['2026-10-01 00:00:00', '2026-09-01 00:00:00']);
});

it('leaves the start open without since', function () {
    $query = window('2026-10-01 00:00:00')->constrain(DB::table('orders'), 'paid_at');

    expect($query->toSql())->toBe('select * from "orders" where "paid_at" < ?');
});

it('keeps a row at since and drops a row at until, so consecutive windows meet exactly', function () {
    Schema::create('orders', fn (Blueprint $table) => $table->dateTime('paid_at'));
    DB::table('orders')->insert([
        ['paid_at' => '2026-08-31 23:59:59'],
        ['paid_at' => '2026-09-01 00:00:00'],
        ['paid_at' => '2026-09-30 23:59:59'],
        ['paid_at' => '2026-10-01 00:00:00'],
    ]);

    $first = window('2026-09-01 00:00:00')->constrain(DB::table('orders'), 'paid_at')->pluck('paid_at')->all();
    $second = window('2026-10-01 00:00:00', '2026-09-01 00:00:00')->constrain(DB::table('orders'), 'paid_at')->pluck('paid_at')->all();

    expect($first)->toBe(['2026-08-31 23:59:59'])
        ->and($second)->toBe(['2026-09-01 00:00:00', '2026-09-30 23:59:59']);
});

it('takes an Eloquent query and hands the same one back', function () {
    $query = User::query();

    expect(window('2026-10-01 00:00:00')->constrain($query, 'created_at'))->toBe($query)
        ->and($query->toSql())->toStartWith('select * from "users" where "created_at" < ?');
});

it('holds its bounds in the app timezone, because bindings use the wall clock', function () {
    config(['app.timezone' => 'Europe/Amsterdam']);

    $window = new BackfillWindow(CarbonImmutable::parse('2026-02-28 23:30:00', 'UTC'));

    expect($window->until->tzName)->toBe('Europe/Amsterdam')
        ->and(DB::connection()->prepareBindings($window->constrain(DB::table('orders'), 'paid_at')->getBindings()))
        ->toBe(['2026-03-01 00:30:00']);
});

it('refuses since at or after until', function () {
    window('2026-10-01 00:00:00', '2026-10-01 00:00:00');
})->throws(InvalidArgumentException::class);
