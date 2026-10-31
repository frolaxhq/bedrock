<?php

declare(strict_types=1);

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Workbench\App\Models\User;

beforeEach(function () {
    config()->set('bedrock.auth.enabled', true);
    config()->set('bedrock.admin.enabled', true);
    Route::middleware('web')->group(dirname(__DIR__, 2).'/routes/foundation.php');
    $this->withoutVite();
});

it('renders login and protects the admin dashboard', function () {
    $this->get('/login')->assertOk();
    $this->get('/admin')->assertRedirect('/login');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin')->assertForbidden();
    app(AdministrationManager::class)->grantAdministrator($user);
    $this->actingAs($user->fresh())->get('/admin')->assertOk();
});

it('logs in and logs out with session authentication', function () {
    $user = User::factory()->create(['password' => Hash::make('valid-password')]);
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'valid-password'])->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('disables public registration by default and never grants admin access', function () {
    $this->post('/register', [])->assertNotFound();
    config()->set('bedrock.auth.registration', true);
    $this->post('/register', ['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'roles' => ['bedrock-admin']])->assertRedirect('/verify-email');
    $user = User::where('email', 'customer@example.test')->firstOrFail();
    expect($user->can('bedrock.admin'))->toBeFalse();
});

it('updates profile and requires the current password to change passwords', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $this->actingAs($user)->patch('/profile', ['name' => 'Updated', 'email' => 'updated@example.test'])->assertSessionHas('status');
    expect($user->fresh()->email_verified_at)->toBeNull();
    $this->put('/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('current_password');
    $this->put('/password', ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('resets a password using a valid broker token only', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    $this->post('/reset-password', ['email' => $user->email, 'token' => 'invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('email');
    $token = Password::createToken($user);
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect('/login');
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('verifies email only with a signed link for the current user', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertForbidden();
    $this->get($url)->assertRedirect('/profile');
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('throttles repeated credential failures', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many');
});

it('shares only authorized project navigation and respects the admin prefix', function () {
    $routes = new RouteCollection;
    foreach (Route::getRoutes() as $route) {
        if ($route->getName() !== 'bedrock.dashboard') {
            $routes->add($route);
        }
    }
    Route::setRoutes($routes);
    config()->set('bedrock.admin.path', 'control');
    config()->set('bedrock.admin.navigation', [['label' => 'Private', 'route' => 'profile.show', 'permission' => 'private.view']]);
    Route::middleware('web')->group(dirname(__DIR__, 2).'/routes/foundation.php');
    Route::getRoutes()->refreshNameLookups();
    app('url')->setRoutes(Route::getRoutes());
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    $this->actingAs($user)->get('/control', ['X-Inertia' => 'true'])->assertOk()
        ->assertJsonPath('component', 'bedrock/Dashboard')
        ->assertJsonPath('props.__bedrock.navigation.0.href', '/control')
        ->assertJsonMissing(['label' => 'Private']);
});

it('rejects a signed verification link belonging to another user', function () {
    $owner = User::factory()->create(['email_verified_at' => null]);
    $other = User::factory()->create(['email_verified_at' => null]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $owner->id, 'hash' => sha1($owner->email)]);
    $this->actingAs($other)->get($url)->assertForbidden();
    expect($owner->fresh()->email_verified_at)->toBeNull();
});

it('creates an administrator interactively without changing an existing account', function () {
    $this->artisan('bedrock:admin-create')->expectsQuestion('Name', 'Administrator')
        ->expectsQuestion('Email', 'first@example.test')->expectsQuestion('Password', 'secure-password')
        ->expectsQuestion('Confirm password', 'secure-password')->assertSuccessful();
    expect(User::where('email', 'first@example.test')->firstOrFail()->can('bedrock.admin'))->toBeTrue();
});

it('does not duplicate host navigation across consecutive Inertia responses', function () {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)->get('/admin', ['X-Inertia' => 'true'])
            ->assertOk()->assertJsonCount(1, 'props.__bedrock.navigation');
    }
});

it('refuses installation when an unnamed application route occupies an auth endpoint', function () {
    Route::post('/logout', static fn (): string => 'Existing application logout');
    $this->artisan('bedrock:install', ['--dry-run' => true])
        ->expectsOutputToContain('Route conflict: logout')
        ->assertFailed();
});
