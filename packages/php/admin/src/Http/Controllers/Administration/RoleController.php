<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Administration;

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class RoleController
{
    public function __construct(protected AdministrationManager $administration) {}

    public function index(): View
    {
        return view('bedrock::admin.roles.index', [
            'roles' => $this->administration->roles(),
            'permissions' => $this->administration->grantablePermissions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        try {
            $this->administration->createRole($validated['name']);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('bedrock.admin.roles.index')->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.roles.index')->with('status', 'Role created.');
    }

    public function permissions(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        try {
            $this->administration->syncRolePermissions($role, array_values($validated['permissions'] ?? []));
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('bedrock.admin.roles.index')->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.roles.index')->with('status', sprintf('Permissions for [%s] updated.', $role->name));
    }
}
