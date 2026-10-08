<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the protected owner role in step with the permissions table.
 *
 * The owner role cannot be edited from the UI, so permissions added later
 * (by migrations or by saving another role) would otherwise never reach it.
 * The sync is additive and idempotent: it never detaches anything.
 */
class OwnerPermissionSyncService
{
    public const OWNER_ROLE_ID = 1;

    public function isOwner($user): bool
    {
        return $user !== null
            && $user->roles()->where('roles.id', self::OWNER_ROLE_ID)->exists();
    }

    /**
     * Attach every permission the owner role is missing.
     *
     * @return array{added: string[], added_count: int, total: int}
     */
    public function sync(): array
    {
        return DB::transaction(function () {
            $owner = Role::lockForUpdate()->findOrFail(self::OWNER_ROLE_ID);

            $missing = Permission::whereNotIn(
                'id',
                $owner->permissions()->pluck('permissions.id')
            )->get(['id', 'name']);

            if ($missing->isNotEmpty()) {
                $owner->permissions()->attach($missing->pluck('id')->all());
            }

            return [
                'added' => $missing->pluck('name')->all(),
                'added_count' => $missing->count(),
                'total' => $owner->permissions()->count(),
            ];
        });
    }
}
