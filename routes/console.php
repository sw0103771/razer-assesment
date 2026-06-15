<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('processed-payments:rotate', function () {
    $path = storage_path('app/processed_payments.log');

    if (!file_exists($path)) {
        $this->info('No processed payments file found.');
        return 0;
    }

    $archivePath = storage_path('app/processed_payments_' . now()->format('Ymd_His') . '.log');
    rename($path, $archivePath);

    file_put_contents($path, '');

    $this->info("Rotated processed payments file to {$archivePath}");
    return 0;
});
