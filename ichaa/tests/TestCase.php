<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RefreshSharedPostgresDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshSharedPostgresDatabase;

    protected function setUpTraits()
    {
        $originalTraits = $this->traitsUsedByTest;
        $uses = $originalTraits;

        if (isset($uses[RefreshDatabase::class])) {
            $this->refreshSharedPostgresDatabase();
            unset($uses[RefreshDatabase::class]);
        }

        $this->traitsUsedByTest = $uses;

        try {
            return parent::setUpTraits();
        } finally {
            $this->traitsUsedByTest = $originalTraits;
        }
    }

    protected function createVerifiedAdminUser(array $attributes = []): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            ...$attributes,
        ]);

        Role::findOrCreate('admin', 'web');
        $user->assignRole('admin');

        return $user;
    }
}
