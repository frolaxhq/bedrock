<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\Events\Settings\ModuleSettingsChanged;
use Frolax\Bedrock\Models\ModuleSetting;
use Frolax\Bedrock\Settings;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->seedModule('billing', [
        'manifest' => [
            'key' => 'billing',
            'name' => 'Billing',
            'version' => '1.0.0',
            'provider' => 'Modules\\Billing\\BillingServiceProvider',
            'settings' => [
                'currency' => [
                    'type' => 'select',
                    'label' => 'Currency',
                    'default' => 'USD',
                    'rules' => ['required', 'string'],
                    'options' => ['USD' => 'US Dollar', 'BDT' => 'Bangladeshi Taka'],
                ],
                'enabled' => true,
                'api_key' => [
                    'type' => 'secret',
                    'label' => 'API key',
                    'rules' => ['nullable', 'string'],
                ],
            ],
        ],
    ]);
});

it('normalizes typed and shorthand settings with persisted values over defaults', function (): void {
    $settings = app(Settings::class);

    expect($settings->definitions('billing')->pluck('type', 'key')->all())
        ->toBe(['currency' => 'select', 'enabled' => 'boolean', 'api_key' => 'secret'])
        ->and($settings->all('billing'))->toMatchArray(['currency' => 'USD', 'enabled' => true, 'api_key' => null]);

    $settings->update('billing', ['currency' => 'BDT', 'enabled' => false]);

    expect($settings->all('billing'))->toMatchArray(['currency' => 'BDT', 'enabled' => false]);
});

it('validates a complete update before persisting any values', function (): void {
    app(Settings::class)->set('billing', 'currency', 'USD');

    expect(fn () => app(Settings::class)->update('billing', ['currency' => 'EUR', 'enabled' => false]))
        ->toThrow(InvalidArgumentException::class)
        ->and(app(Settings::class)->get('billing', 'currency'))->toBe('USD')
        ->and(app(Settings::class)->get('billing', 'enabled'))->toBeTrue();
});

it('encrypts secrets and redacts them from administration payloads', function (): void {
    Event::fake([ModuleSettingsChanged::class]);
    $settings = app(Settings::class);
    $settings->set('billing', 'api_key', 'top-secret');
    $stored = ModuleSetting::query()->where('module_key', 'billing')->where('setting_key', 'api_key')->firstOrFail();
    $payload = app(ModuleSettings::class)->forInertia('billing');

    expect($stored->encrypted)->toBeTrue()
        ->and($stored->value)->not->toContain('top-secret')
        ->and($settings->get('billing', 'api_key'))->toBe('top-secret')
        ->and($payload['values'])->not->toHaveKey('api_key')
        ->and($payload['definitions'][2]['configured'])->toBeTrue();

    Event::assertDispatched(ModuleSettingsChanged::class, fn (ModuleSettingsChanged $event): bool => $event->module === 'billing' && $event->keys === ['api_key']);
});

it('resets one setting without removing retained module settings', function (): void {
    $settings = app(Settings::class);
    $settings->update('billing', ['currency' => 'BDT', 'enabled' => false]);
    $settings->reset('billing', 'currency');

    expect($settings->get('billing', 'currency'))->toBe('USD')
        ->and($settings->get('billing', 'enabled'))->toBeFalse();
});
