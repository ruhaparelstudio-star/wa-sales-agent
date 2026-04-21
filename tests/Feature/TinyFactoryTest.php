<?php

test('tenant factory works', function () {
    $tenant = \App\Modules\Tenancy\Models\Tenant::factory()->create();
    expect($tenant->id)->toBeGreaterThan(0);
});
