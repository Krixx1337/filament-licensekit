<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Users\ConfiguredUserModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensekit_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 16)->default('active');
            $table->dateTime('free_from')->nullable();
            $table->dateTime('free_until')->nullable();
            $table->boolean('device_lock')->default(true);
            $table->unsignedSmallInteger('device_move_cooldown_days')->nullable()->default(7);
            $table->timestamps();
        });
        Schema::create('licensekit_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('licensekit_api_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->json('allowed_product_ids')->nullable();
            $table->unsignedInteger('max_per_request')->default(100);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('licensekit_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_connection_id')->nullable()->constrained('licensekit_api_connections')->nullOnDelete();
            $table->string('created_by');
            $table->string('source', 8);
            $table->string('issuance_key', 64)->unique();
            $table->string('request_hash', 64);
            $table->string('reference', 128);
            $table->foreignId('plan_id')->constrained('licensekit_plans')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->index(['api_connection_id', 'id']);
        });
        Schema::create('licensekit_licenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->boolean('is_lifetime')->default(false);
            $table->string('status', 16)->default('active');
            $table->dateTime('suspended_until')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('paused_until')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('remaining_seconds')->nullable();
            $device = $table->text('device')->nullable();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $device->collation('utf8mb4_bin');
            }
            $table->timestamp('device_bound_at')->nullable();
            $table->timestamp('device_reset_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
        Schema::create('licensekit_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('licensekit_batches')->restrictOnDelete();
            $table->foreignId('license_id')->nullable()->constrained('licensekit_licenses')->restrictOnDelete();
            $table->string('key', 29)->unique();
            $table->string('note')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'redeemed_at', 'cancelled_at'], 'unused_keys');
        });
        Schema::create('licensekit_loader_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->cascadeOnDelete();
            $device = $table->text('device');
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $device->collation('utf8mb4_bin');
            }
            $table->string('user_fingerprint', 64);
            $table->dateTime('expires_at');
            $table->dateTime('last_seen_at');
            $table->timestamp('created_at');
            $table->index(['user_id', 'product_id']);
        });
        Schema::create('licensekit_device_bans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('licensekit_products')->cascadeOnDelete();
            $device = $table->text('device');
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $device->collation('utf8mb4_bin');
            }
            $table->string('device_hash', 64)->index();
            $table->text('note')->nullable();
            $table->string('created_by');
            $table->timestamp('created_at');
        });
        Schema::create('licensekit_activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('actor');
            $table->string('event');
            $table->string('subject_type');
            $table->string('subject_id', 64);
            $table->json('meta');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['licensekit_device_bans', 'licensekit_loader_sessions', 'licensekit_activity_log', 'licensekit_keys', 'licensekit_licenses', 'licensekit_batches', 'licensekit_api_connections', 'licensekit_plans', 'licensekit_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
