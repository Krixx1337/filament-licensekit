<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Users\ConfiguredUserModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensekit_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('licensekit_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_group_id')->nullable()->constrained('licensekit_product_groups')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_blocked')->default(false);
            $table->boolean('requires_hwid')->default(true);
            $table->timestamps();
        });
        Schema::create('licensekit_licensing_bans', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->text('reason');
            $table->string('created_by');
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'product_id', 'lifted_at', 'expires_at'], 'licensing_ban_scope');
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
            $table->string('issuer');
            $table->string('reference', 128);
            $table->foreignId('license_plan_id')->constrained('licensekit_license_plans')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['issuer', 'reference']);
        });
        Schema::create('licensekit_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->boolean('is_lifetime')->default(false);
            $table->boolean('is_revoked')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->unsignedBigInteger('remaining_seconds')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
        Schema::create('licensekit_license_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_plan_id')->constrained('licensekit_license_plans')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('licensekit_batches')->restrictOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained('licensekit_entitlements')->restrictOnDelete();
            $key = $table->string('key', 255)->unique();
            if (DB::connection()->getDriverName() === 'mysql') {
                $key->charset('ascii')->collation('ascii_bin');
            }
            $table->string('note')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['license_plan_id', 'claimed_at', 'revoked_at'], 'unclaimed_keys');
        });
        Schema::create('licensekit_device_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 64);
            $table->text('hwid')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_hwid_reset_at')->nullable();
            $table->unique(['user_id', 'scope_key']);
            $table->timestamps();
        });
        Schema::create('licensekit_device_reset_history', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 64);
            $table->string('scope', 16);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamp('reset_at');
            $table->string('performed_by', 16);
            $table->string('actor_id', 64);
            $table->text('reason')->nullable();
            $table->text('before_hwid')->nullable();
            $table->index(['user_id', 'id']);
            $table->index(['product_id', 'id']);
        });
        Schema::create('licensekit_import_records', function (Blueprint $table): void {
            $table->id();
            $table->string('identity', 64)->unique();
            $table->string('source_namespace', 128);
            $table->string('record_type', 32);
            $table->string('source_reference', 128);
            $table->string('payload_hash', 64);
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('actor');
            $table->timestamps();
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
        foreach (['licensekit_import_records', 'licensekit_audit_events', 'licensekit_device_reset_history', 'licensekit_device_bindings', 'licensekit_license_keys', 'licensekit_entitlements', 'licensekit_batches', 'licensekit_api_clients', 'licensekit_license_plans', 'licensekit_licensing_bans', 'licensekit_products', 'licensekit_product_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
