<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';

if (in_array('--env=testing', $argv, true)) {
    $app->loadEnvironmentFrom('.env.testing');
}

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

/** @var User $user */
$user = User::updateOrCreate(
    ['email' => 'e2e@example.com'],
    [
        'name' => 'E2E User',
        'password' => 'password',
    ]
);

$user->forceFill([
    'email_verified_at' => now(),
])->save();

echo "E2E user ready.\n";
