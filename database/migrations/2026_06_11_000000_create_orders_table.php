<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->unsignedBigInteger('amount'); // amount in cents
            $table->string('currency', 3);
            $table->enum('status', ['PENDING', 'PAID', 'FAILED', 'REFUNDED'])->default('PENDING');
            $table->string('gateway_payment_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('gateway_payment_id');
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
