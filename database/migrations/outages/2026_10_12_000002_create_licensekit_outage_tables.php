<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensekit_outages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->string('reference', 128)->unique();
            $table->string('request_hash', 64);
            $table->text('reason');
            $table->boolean('access_blocked')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'ended_at']);
        });
        Schema::create('licensekit_compensation_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->foreignId('outage_id')->nullable()->constrained('licensekit_outages')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('reference', 128);
            $table->string('request_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('seconds')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->text('reason');
            $table->string('actor');
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('credited_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->string('failure')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'reference']);
            $table->index(['product_id', 'status']);
        });
        Schema::create('licensekit_compensation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('licensekit_compensation_operations')->restrictOnDelete();
            $table->foreignId('entitlement_id')->constrained('licensekit_entitlements')->restrictOnDelete();
            $table->unsignedBigInteger('seconds');
            $table->string('result', 16)->nullable();
            $table->unsignedBigInteger('credited_seconds')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unique(['operation_id', 'entitlement_id'], 'compensation_entry_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licensekit_compensation_entries');
        Schema::dropIfExists('licensekit_compensation_operations');
        Schema::dropIfExists('licensekit_outages');
    }
};
