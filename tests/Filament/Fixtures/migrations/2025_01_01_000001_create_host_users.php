<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Users\ConfiguredUserModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new (ConfiguredUserModel::className()))->getTable(), function (Blueprint $table): void {
            $model = new (ConfiguredUserModel::className());
            if ($model->getKeyType() === 'string') {
                $table->string('id', 36)->primary();
            } else {
                $table->id();
            }
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
