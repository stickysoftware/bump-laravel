<?php

use App\Bump\GeneratedOrdersBackfill;
use Illuminate\Support\Facades\File;
use StickySoftware\Bump\Contracts\Backfill;

// Generated classes and published stubs land in Testbench's skeleton app, shared by every test.
function removeGeneratedFiles(): void
{
    File::deleteDirectory(app_path('Bump'));
    File::delete(base_path('stubs/bump-backfill.stub'));

    if (File::isDirectory(base_path('stubs')) && File::isEmptyDirectory(base_path('stubs'))) {
        File::deleteDirectory(base_path('stubs'));
    }
}

beforeEach(fn () => removeGeneratedFiles());
afterEach(fn () => removeGeneratedFiles());

test('it creates the class in app/Bump with a prefix from its name', function () {
    $this->artisan('make:bump-backfill', ['name' => 'OrdersBackfill'])->assertSuccessful();

    $contents = File::get(app_path('Bump/OrdersBackfill.php'));

    expect($contents)
        ->toContain('namespace App\Bump;')
        ->toContain('class OrdersBackfill implements Backfill')
        ->toContain("'id' => \"orders-{\$row->id}\"")
        ->toContain("'trackable' => 'orders'")
        ->not->toContain('{{');
});

test('the prefix is the kebab-case name without Backfill, in a nested namespace too', function () {
    $this->artisan('make:bump-backfill', ['name' => 'Shop/RefundRequestsBackfill'])->assertSuccessful();

    expect(File::get(app_path('Bump/Shop/RefundRequestsBackfill.php')))
        ->toContain('namespace App\Bump\Shop;')
        ->toContain("'id' => \"refund-requests-{\$row->id}\"");
});

test('the generated class is a backfill', function () {
    $this->artisan('make:bump-backfill', ['name' => 'GeneratedOrdersBackfill'])->assertSuccessful();

    require app_path('Bump/GeneratedOrdersBackfill.php');

    expect(new GeneratedOrdersBackfill)->toBeInstanceOf(Backfill::class);
});

test('it leaves an existing class alone without --force', function () {
    File::ensureDirectoryExists(app_path('Bump'));
    File::put(app_path('Bump/OrdersBackfill.php'), '<?php // mine');

    $this->artisan('make:bump-backfill', ['name' => 'OrdersBackfill']);

    expect(File::get(app_path('Bump/OrdersBackfill.php')))->toBe('<?php // mine');
});

test('--force overwrites an existing class', function () {
    File::ensureDirectoryExists(app_path('Bump'));
    File::put(app_path('Bump/OrdersBackfill.php'), '<?php // mine');

    $this->artisan('make:bump-backfill', ['name' => 'OrdersBackfill', '--force' => true])->assertSuccessful();

    expect(File::get(app_path('Bump/OrdersBackfill.php')))->toContain('class OrdersBackfill implements Backfill');
});

test('it uses the stub published to the app', function () {
    $this->artisan('vendor:publish', ['--tag' => 'bump-stubs'])->assertSuccessful();

    expect(base_path('stubs/bump-backfill.stub'))->toBeFile();

    File::put(base_path('stubs/bump-backfill.stub'), "<?php\n\n// house style: {{ class }} sends {{ prefix }}\n");

    $this->artisan('make:bump-backfill', ['name' => 'OrdersBackfill'])->assertSuccessful();

    expect(File::get(app_path('Bump/OrdersBackfill.php')))->toContain('// house style: OrdersBackfill sends orders');
});
