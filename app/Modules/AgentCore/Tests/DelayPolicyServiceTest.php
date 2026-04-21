<?php

use App\Modules\AgentCore\Services\DelayPolicyService;

test('short messages get 2-5 second delay', function () {
    $service = new DelayPolicyService();
    $short = 'Halo, ada yang bisa dibantu?';

    foreach (range(1, 20) as $_) {
        $delay = $service->getDelay($short);
        expect($delay)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5);
    }
});

test('medium messages get 4-10 second delay', function () {
    $service = new DelayPolicyService();
    $medium = str_repeat('kata ', 60);

    foreach (range(1, 20) as $_) {
        $delay = $service->getDelay($medium);
        expect($delay)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(10);
    }
});

test('long messages get 8-15 second delay', function () {
    $service = new DelayPolicyService();
    $long = str_repeat('kata ', 150);

    foreach (range(1, 20) as $_) {
        $delay = $service->getDelay($long);
        expect($delay)->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(15);
    }
});
