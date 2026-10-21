<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\ModuleRegistry;
use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/distributable-generators-'.uniqid();
    File::ensureDirectoryExists($this->root.'/apps/Payments/app/Providers');
    config()->set('distributable.declared', ['payments' => []]);
    config()->set('distributable.modules.path', $this->root.'/apps');
    $this->app->forgetInstance(ModuleRegistry::class);
    $this->module = $this->root.'/apps/Payments';
});

afterEach(fn () => File::deleteDirectory($this->root));

it('generates a class in the module and under its namespace with --module, as the plain command does in app/', function () {
    $this->artisan('make:controller PaymentController --module=payments')->assertSuccessful();
    $this->artisan('make:request StorePaymentRequest --module=payments')->assertSuccessful();
    $this->artisan('make:command SendReceipts --module=payments')->assertSuccessful();

    expect(file_get_contents($this->module.'/app/Http/Controllers/PaymentController.php'))->toContain('namespace Apps\Payments\Http\Controllers;')
        ->and(file_get_contents($this->module.'/app/Http/Requests/StorePaymentRequest.php'))->toContain('namespace Apps\Payments\Http\Requests;')
        ->and(file_get_contents($this->module.'/app/Console/Commands/SendReceipts.php'))->toContain('namespace Apps\Payments\Console\Commands;');
});

it('generates a model with its migration and its factory in the module, the factory under the module namespace', function () {
    $this->artisan('make:model Payment -mf --module=payments')->assertSuccessful();

    expect(file_get_contents($this->module.'/app/Models/Payment.php'))->toContain('namespace Apps\Payments\Models;')
        ->and(file_get_contents($this->module.'/database/factories/PaymentFactory.php'))
        ->toContain('namespace Apps\Payments\Database\Factories;')
        ->toContain('use Apps\Payments\Models\Payment;')
        ->and(glob($this->module.'/database/migrations/*_create_payments_table.php'))->toHaveCount(1);
});

it('generates a seeder, a migration and a config file in the module', function () {
    $this->artisan('make:seeder PaymentSeeder --module=payments')->assertSuccessful();
    $this->artisan('make:migration add_refunded_at_to_payments --module=payments')->assertSuccessful();
    $this->artisan('make:config payments --module=payments')->assertSuccessful();

    expect(file_get_contents($this->module.'/database/seeders/PaymentSeeder.php'))->toContain('namespace Apps\Payments\Database\Seeders;')
        ->and(glob($this->module.'/database/migrations/*_add_refunded_at_to_payments.php'))->toHaveCount(1)
        ->and(is_file($this->module.'/config/payments.php'))->toBeTrue();
});

it('generates a view in the module, and names it with the module in the class that renders it', function () {
    $this->artisan('make:view payments.index --module=payments')->assertSuccessful();
    $this->artisan('make:component Alert --module=payments')->assertSuccessful();
    $this->artisan('make:mail ReceiptSent --markdown=mail.receipt-sent --module=payments')->assertSuccessful();

    expect(is_file($this->module.'/resources/views/payments/index.blade.php'))->toBeTrue()
        ->and(is_file($this->module.'/resources/views/components/alert.blade.php'))->toBeTrue()
        ->and(file_get_contents($this->module.'/app/View/Components/Alert.php'))
        ->toContain('namespace Apps\Payments\View\Components;')
        ->toContain("view('payments::components.alert')")
        ->and(is_file($this->module.'/resources/views/mail/receipt-sent.blade.php'))->toBeTrue()
        ->and(file_get_contents($this->module.'/app/Mail/ReceiptSent.php'))->toContain("'payments::mail.receipt-sent'");
});

it('generates a test in the module tests, under the module namespace, and leaves none in the application tests', function () {
    $this->artisan('make:test PaymentTest --phpunit --module=payments')->assertSuccessful();
    $this->artisan('make:test TotalTest --unit --phpunit --module=payments')->assertSuccessful();

    expect(file_get_contents($this->module.'/tests/Feature/PaymentTest.php'))->toContain('namespace Apps\Payments\Tests\Feature;')
        ->and(file_get_contents($this->module.'/tests/Unit/TotalTest.php'))->toContain('namespace Apps\Payments\Tests\Unit;')
        ->and(is_file(base_path('tests/Feature/PaymentTest.php')))->toBeFalse()
        ->and(is_file(base_path('tests/Unit/TotalTest.php')))->toBeFalse();
});

it('gives the application its paths and its namespace back once the command ends', function () {
    $paths = [app_path(), database_path(), config_path(), config('view.paths'), $this->app->getNamespace()];

    $this->artisan('make:model Payment -mf --module=payments')->assertSuccessful();

    expect([app_path(), database_path(), config_path(), config('view.paths'), $this->app->getNamespace()])->toBe($paths);
});

it('finds the models of the module for model:prune with --module, and only them without it', function () {
    File::ensureDirectoryExists($this->module.'/app/Models');
    file_put_contents($this->module.'/app/Models/Payment.php', <<<'PHP'
        <?php

        namespace Apps\Payments\Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Prunable;

        class Payment extends Model
        {
            use Prunable;

            public function pruneAll(int $chunkSize = 1000): int
            {
                return 0;
            }
        }
        PHP);
    require_once $this->module.'/app/Models/Payment.php';

    $this->artisan('model:prune --module=payments')->expectsOutputToContain('No prunable [Apps\Payments\Models\Payment] records found.');
    $this->artisan('model:prune')->expectsOutputToContain('No prunable models found.');
});

it('gives any command run with --module the paths and the namespace of the module, its views before the application\'s', function () {
    $scan = new class extends Command
    {
        protected $signature = 'acme:scan';

        public function handle(): void
        {
            $this->line(implode(' ', [app_path(), database_path(), config_path(), ...config('view.paths'), app()->getNamespace()]));
        }
    };
    Artisan::registerCommand($this->app->make($scan::class));
    $views = implode(' ', config('view.paths'));
    $application = implode(' ', [app_path(), database_path(), config_path(), ...config('view.paths'), $this->app->getNamespace()]);

    $this->artisan('acme:scan --module=payments')
        ->expectsOutput("{$this->module}/app {$this->module}/database {$this->module}/config {$this->module}/resources/views {$views} Apps\Payments\\")
        ->assertSuccessful();
    $this->artisan('acme:scan')->expectsOutput($application)->assertSuccessful();
});

it('offers --module on every generator but make:provider, a module having one provider', function () {
    $offers = fn (string $command): bool => Artisan::all()[$command]->getDefinition()->hasOption('module');

    expect($offers('make:model'))->toBeTrue()
        ->and($offers('make:migration'))->toBeTrue()
        ->and($offers('make:factory'))->toBeTrue()
        ->and($offers('make:test'))->toBeTrue()
        ->and($offers('make:view'))->toBeTrue()
        ->and($offers('make:component'))->toBeTrue()
        ->and($offers('make:provider'))->toBeFalse();
});

it('refuses a module nobody declared', function () {
    $this->artisan('make:model Payment --module=ghost');
})->throws(ModuleException::class, 'Unknown module [ghost]');
