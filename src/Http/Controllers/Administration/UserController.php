<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Administration;

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class UserController
{
    public function __construct(protected AdministrationManager $administration) {}

    public function index(): View
    {
        /** @var class-string<Model> $model */
        $model = $this->administration->userModel();
        $users = $model::query()->orderBy('id')->paginate(25);

        return view('bedrock::admin.users.index', [
            'users' => $users,
            'roles' => $this->administration->roles(),
        ]);
    }

    public function roles(Request $request, string $user): RedirectResponse
    {
        $validated = $request->validate(['roles' => ['sometimes', 'array'], 'roles.*' => ['string']]);
        /** @var class-string<Model> $model */
        $model = $this->administration->userModel();
        $account = $model::query()->findOrFail($user);

        try {
            $this->administration->syncUserRoles($account, array_values($validated['roles'] ?? []));
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('bedrock.admin.users.index')->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.users.index')->with('status', 'User roles updated.');
    }
}
