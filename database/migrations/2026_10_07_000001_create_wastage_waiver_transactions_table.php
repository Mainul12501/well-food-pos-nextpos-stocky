<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWastageWaiverTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('wastage_waiver_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('provider_id');
            $table->char('period', 7);
            $table->date('date');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('rate', 5, 2)->nullable();
            $table->decimal('base_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('payment_purchase_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'period']);
            $table->index(['purchase_id', 'type']);
            $table->index('payment_purchase_id');
            $table->index('reversal_of_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wastage_waiver_transactions');
    }
}
