<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\AgentCore\Models\LlmUsageLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class SuperAdminUsageController extends Controller
{
    public function index(): View
    {
        $rows = LlmUsageLog::selectRaw(
            'tenant_id,
             SUM(prompt_tokens) as prompt_tokens,
             SUM(completion_tokens) as completion_tokens,
             SUM(total_tokens) as total_tokens,
             COUNT(*) as call_count'
        )
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->groupBy('tenant_id')
            ->orderByDesc('total_tokens')
            ->paginate(20);

        $tenantIds = $rows->pluck('tenant_id')->unique();
        $tenants   = Tenant::whereIn('id', $tenantIds)->get()->keyBy('id');

        return view('superadmin.usage.index', compact('rows', 'tenants'));
    }
}
