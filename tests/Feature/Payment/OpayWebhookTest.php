<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('opay webhook is not registered in the current production release', function () {
    $this->postJson('/api/webhooks/opay', [
        'status' => 'SUCCESS',
        'transactionId' => 'OPAY-DEFERRED-TEST',
    ])->assertNotFound();
});
