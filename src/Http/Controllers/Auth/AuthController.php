<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController
{
    public function login(): Response
    {
        return Inertia::render('bedrock/Login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'bedrock-login:'.hash('sha256', Str::lower($request->string('email')->toString()).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => 1])]);
        }

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended($this->home($request));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function registration(): Response
    {
        abort_unless(config('bedrock.auth.registration'), 404);

        return Inertia::render('bedrock/Register');
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(config('bedrock.auth.registration'), 404);
        $model = $this->model();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique($model, 'email')],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $user = new $model;
        $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])])->save();
        event(new Registered($user));

        // Laravel's Registered listener sends this for MustVerifyEmail users.
        if (! $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    public function forgot(): Response
    {
        return Inertia::render('bedrock/ForgotPassword');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker()->sendResetLink($request->only('email'));

        return back()->with('status', 'If an account exists, a password reset link has been sent.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('bedrock/ResetPassword', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function updatePasswordFromToken(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $status = Password::broker()->reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', __($status));
    }

    public function notice(): Response
    {
        return Inertia::render('bedrock/VerifyEmail');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect($this->home($request))->with('status', 'Email verified.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Verification link sent.');
    }

    public function profile(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('bedrock/Profile', ['profile' => ['name' => $user->getAttribute('name'), 'email' => $user->getAttribute('email'), 'verified' => $user->hasVerifiedEmail()]]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique($user::class, 'email')->ignore($user->getKey(), $user->getKeyName())]]);
        $user->forceFill($data);

        if ($user->isDirty('email')) {
            $user->setAttribute('email_verified_at', null);
        }
        $user->save();

        return back()->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password:web'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $user = $this->user($request);
        $user->forceFill(['password' => Hash::make($data['password'])])->setRememberToken(Str::random(60));
        $user->save();
        $request->session()->regenerate();

        return back()->with('status', 'Password updated.');
    }

    protected function home(Request $request): string
    {
        return $request->user('web')?->can((string) config('bedrock.permissions.admin_permission', 'bedrock.admin')) && config('bedrock.admin.enabled')
            ? route('bedrock.dashboard', [], false) : route('profile.show', [], false);
    }

    /** @return class-string<User> */
    protected function model(): string
    {
        $provider = config('auth.guards.web.provider', 'users');
        $model = config('auth.providers.'.$provider.'.model');
        abort_unless(is_string($model) && is_subclass_of($model, User::class), 500, 'Bedrock requires an Eloquent authenticatable user.');

        return $model;
    }

    protected function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
