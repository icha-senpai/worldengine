<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\Models\Role;

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
        'name' => User::FOOTMOUTHKICK_NAME,
        'password' => 'password',
    ]
);

$user->forceFill([
    'email_verified_at' => now(),
])->save();

Role::findOrCreate(User::ROLE_ADMIN, 'web');
$user->assignRole(User::ROLE_ADMIN);

echo "E2E user ready.\n";
