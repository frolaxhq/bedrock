<?php

declare(strict_types=1);

namespace Modules\AuditLog\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\AuditLog\AuditEntry;

class AuditController
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:128'], 'module' => ['nullable', 'string', 'max:255'],
            'actor' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $query = AuditEntry::query()->orderByDesc('id');

        foreach (['event' => 'event', 'module' => 'module_key', 'actor' => 'actor_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (isset($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }

        return view()->make('audit-log::index', ['entries' => $query->paginate(50)->withQueryString(), 'filters' => $filters]);
    }
}
