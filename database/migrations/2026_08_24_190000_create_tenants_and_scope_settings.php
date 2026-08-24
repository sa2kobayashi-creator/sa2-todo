<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('status', 20)->default('active');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('max_users')->default(5);
            $table->boolean('allow_own_keys')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('tenant_scope')->default(0)->after('tenant_id');
        });

        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        $this->dropProviderOnlyUnique();

        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->unique(['tenant_scope', 'provider']);
        });

        Schema::table('translation_api_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('tenant_scope')->default(0)->after('tenant_id');
            $table->index(['tenant_scope', 'provider']);
        });

        Schema::table('translation_api_keys', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        Schema::table('holiday_entries', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'date']);
        });

        Schema::table('weekday_rules', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('weekday_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('holiday_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('translation_api_keys', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_scope', 'provider']);
            $table->dropColumn(['tenant_id', 'tenant_scope']);
        });
        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->dropUnique(['tenant_scope', 'provider']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'tenant_scope']);
            $table->unique('provider');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('tenants');
    }

    private function dropProviderOnlyUnique(): void
    {
        try {
            Schema::table('media_storage_settings', function (Blueprint $table) {
                $table->dropUnique(['provider']);
            });

            return;
        } catch (\Throwable) {
            // インデックス名が環境で違う場合は一覧から探す
        }

        foreach (Schema::getIndexes('media_storage_settings') as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }
            $columns = $index['columns'] ?? [];
            if ($columns === ['provider']) {
                Schema::table('media_storage_settings', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });

                return;
            }
        }
    }
};
