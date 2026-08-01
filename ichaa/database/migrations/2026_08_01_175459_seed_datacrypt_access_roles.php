<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $roleNames = [
            User::ROLE_ADMIN,
            ...array_keys(User::ACCESS_ROLE_LABELS),
        ];

        foreach ($roleNames as $roleName) {
            DB::table('roles')->insertOrIgnore([
                [
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->update(['updated_at' => $now]);
        }

        $adminRoleId = DB::table('roles')
            ->where('name', User::ROLE_ADMIN)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $adminRoleId) {
            return;
        }

        $footmouthkickUser = DB::table('users')
            ->where('email', User::FOOTMOUTHKICK_EMAIL)
            ->orWhere('name', User::FOOTMOUTHKICK_NAME)
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [User::FOOTMOUTHKICK_EMAIL])
            ->first();

        DB::table('model_has_roles')
            ->where('role_id', $adminRoleId)
            ->where('model_type', User::class)
            ->when($footmouthkickUser, fn ($query) => $query->where('model_id', '!=', $footmouthkickUser->id))
            ->delete();

        if ($footmouthkickUser) {
            DB::table('model_has_roles')->updateOrInsert([
                'role_id' => $adminRoleId,
                'model_type' => User::class,
                'model_id' => $footmouthkickUser->id,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $accessRoleIds = DB::table('roles')
            ->whereIn('name', array_keys(User::ACCESS_ROLE_LABELS))
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($accessRoleIds->isEmpty()) {
            return;
        }

        DB::table('model_has_roles')
            ->whereIn('role_id', $accessRoleIds)
            ->where('model_type', User::class)
            ->delete();

        DB::table('roles')
            ->whereIn('id', $accessRoleIds)
            ->delete();
    }
};
