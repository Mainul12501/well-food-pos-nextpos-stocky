<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWastageWaiverAmountToPurchaseReturnsTable extends Migration
{
    public function up()
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->double('wastage_waiver_amount')->default(0)->after('return_type');
        });
    }

    public function down()
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn('wastage_waiver_amount');
        });
    }
}
