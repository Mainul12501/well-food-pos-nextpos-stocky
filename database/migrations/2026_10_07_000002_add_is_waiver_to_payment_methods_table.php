<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddIsWaiverToPaymentMethodsTable extends Migration
{
    public function up()
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_waiver')->default(false)->after('name');
        });

        if (! DB::table('payment_methods')->where('is_waiver', 1)->exists()) {
            DB::table('payment_methods')->insert([
                'name' => 'Wastage Waiver',
                'is_waiver' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('payment_methods')->where('is_waiver', 1)->delete();

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_waiver');
        });
    }
}
