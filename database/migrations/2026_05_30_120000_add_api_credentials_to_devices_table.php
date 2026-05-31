<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('api_transport', 16)->nullable()->after('mtu_status');
            $table->string('api_host', 128)->nullable()->after('api_transport');
            $table->unsignedSmallInteger('api_port')->nullable()->after('api_host');
            $table->string('api_username', 128)->nullable()->after('api_port');
            $table->text('api_password')->nullable()->after('api_username');
            $table->text('api_token')->nullable()->after('api_password');
            $table->boolean('api_verify_tls')->default(true)->after('api_token');
            $table->string('api_auth_type', 16)->nullable()->after('api_verify_tls');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'api_transport',
                'api_host',
                'api_port',
                'api_username',
                'api_password',
                'api_token',
                'api_verify_tls',
                'api_auth_type',
            ]);
        });
    }
};
