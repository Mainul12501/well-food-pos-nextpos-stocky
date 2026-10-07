<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddWastageWaiverPermission extends Migration
{
    protected $permissions = ['Wastage_Tracker_view', 'Wastage_Waiver_view'];

    public function up()
    {
        $owner_role = DB::table('roles')->orderBy('id')->first();

        foreach ($this->permissions as $name) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            $permission_id = $permission ? $permission->id : DB::table('permissions')->insertGetId(['name' => $name]);

            if ($owner_role) {
                $attached = DB::table('permission_role')
                    ->where('permission_id', $permission_id)
                    ->where('role_id', $owner_role->id)
                    ->exists();

                if (! $attached) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permission_id,
                        'role_id' => $owner_role->id,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        $permission = DB::table('permissions')->where('name', 'Wastage_Waiver_view')->first();

        if ($permission) {
            DB::table('permission_role')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
        }
    }
}
