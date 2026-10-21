<?php

declare(strict_types=1);

use Distributable\Tests\ModuleAppTestCase;
use Illuminate\Support\Facades\Blade;

uses(ModuleAppTestCase::class);

it('loads a module views under the module name', function () {
    expect(trim(view('iam::hello', ['name' => 'Ada'])->render()))->toBe('Hello Ada from iam');
});

it('renders a module anonymous component and its class component under the module name', function () {
    expect(trim(Blade::render('<x-iam::badge>new</x-iam::badge>')))->toBe('<span class="badge">new</span>')
        ->and(trim(Blade::render('<x-iam::alert />')))->toBe('<div class="alert">from the class</div>');
});

it('finds a view in the module first, then in the application, as config does', function () {
    view('welcome');

    expect($this->inModule('iam', fn () => [view()->exists('hello'), view()->exists('welcome')]))->toBe([true, true])
        ->and([view()->exists('hello'), view()->exists('welcome')])->toBe([false, true]);
});

it('leaves a module without views alone', function () {
    expect(view()->exists('analytics::hello'))->toBeFalse();
});
