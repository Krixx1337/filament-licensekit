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
            $table->boolean('requires_hwid')->default(true);
            $table->unsignedSmallInteger('device_move_cooldown_days')->nullable()->default(7);
            $table->timestamps();
        });
        Schema::create('licensekit_license_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('licensekit_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('all_products')->default(false);
            $table->json('allowed_product_ids');
            $table->json('permissions');
            $table->unsignedInteger('maximum_quantity')->default(100);
            $table->timestamps();
        });
        Schema::create('licensekit_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->nullable()->constrained('licensekit_api_clients')->nullOnDelete();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'created_by_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issuer');
            $table->string('reference', 128);
            $table->foreignId('license_plan_id')->constrained('licensekit_license_plans')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['issuer', 'reference']);
            $table->index(['api_client_id', 'id']);
        });
        Schema::create('licensekit_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->boolean('is_lifetime')->default(false);
            $table->string('status', 16)->default('active');
            $table->dateTime('suspended_until')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->unsignedBigInteger('remaining_seconds')->nullable();
            $table->text('device')->nullable();
            $table->timestamp('device_bound_at')->nullable();
            $table->timestamp('device_reset_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
        Schema::create('licensekit_license_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_plan_id')->constrained('licensekit_license_plans')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('licensekit_batches')->restrictOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained('licensekit_entitlements')->restrictOnDelete();
            $table->string('key', 29)->unique();
            $table->string('note')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['license_plan_id', 'claimed_at', 'revoked_at'], 'unclaimed_keys');
        });
        Schema::create('licensekit_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('actor');
            $table->string('event');
            $table->string('subject_type');
            $table->string('subject_id', 64);
            $table->text('reason')->nullable();
            $table->json('before');
            $table->json('after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['licensekit_audit_events', 'licensekit_license_keys', 'licensekit_entitlements', 'licensekit_batches', 'licensekit_api_clients', 'licensekit_license_plans', 'licensekit_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
