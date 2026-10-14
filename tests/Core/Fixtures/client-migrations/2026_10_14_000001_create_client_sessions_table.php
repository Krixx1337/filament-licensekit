<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Users\ConfiguredUserModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ConfiguredUserModel::className(), 'user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('licensekit_products')->restrictOnDelete();
            $table->index(['user_id', 'product_id']);
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->string('user_fingerprint', 64);
            $table->json('hwid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sessions');
    }
};
