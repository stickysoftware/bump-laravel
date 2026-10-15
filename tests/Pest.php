<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use StickySoftware\Bump\Batch;
use StickySoftware\Bump\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'EndToEnd');

/**
 * Answers like the API: one "accepted" result per event after count and amount lists expand.
 */
function fakeApi(): void
{
    Http::fake(fn (Request $request) => Http::response([
        'object' => 'list',
        'data' => collect($request->data()['events'])
            ->flatMap(fn (array $event) => array_fill(0, Batch::size($event), ['object' => 'event', 'id' => 'evt_'.fake()->lexify('????????????????'), 'status' => 'accepted']))
            ->all(),
    ]));
}

/**
 * @return list<array<string, mixed>>
 */
function sentEvents(): array
{
    return collect(Http::recorded())
        ->flatMap(fn (array $pair) => $pair[0]->data()['events'])
        ->all();
}

function inWebRequest(): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());
}
