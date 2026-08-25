<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 失敗を握りつぶす箇所があるため、Postgres でトランザクションを巻き添えにしない。 */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->ensureTenantSchema();
    }

    public function down(): void
    {
        if (Schema::hasTable('weekday_rules') && Schema::hasColumn('weekday_rules', 'tenant_id')) {
            Schema::table('weekday_rules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
        if (Schema::hasTable('holiday_entries') && Schema::hasColumn('holiday_entries', 'tenant_id')) {
            Schema::table('holiday_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
        if (Schema::hasTable('translation_api_keys') && Schema::hasColumn('translation_api_keys', 'tenant_id')) {
            Schema::table('translation_api_keys', function (Blueprint $table) {
                try {
                    $table->dropForeign(['tenant_id']);
                } catch (\Throwable) {
                }
                try {
                    $table->dropIndex(['tenant_scope', 'provider']);
                } catch (\Throwable) {
                }
                $drop = array_values(array_filter(
                    ['tenant_id', 'tenant_scope'],
                    fn (string $column) => Schema::hasColumn('translation_api_keys', $column)
                ));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
        if (Schema::hasTable('media_storage_settings') && Schema::hasColumn('media_storage_settings', 'tenant_scope')) {
            Schema::table('media_storage_settings', function (Blueprint $table) {
                try {
                    $table->dropUnique(['tenant_scope', 'provider']);
                } catch (\Throwable) {
                }
                try {
                    $table->dropForeign(['tenant_id']);
                } catch (\Throwable) {
                }
                $drop = array_values(array_filter(
                    ['tenant_id', 'tenant_scope'],
                    fn (string $column) => Schema::hasColumn('media_storage_settings', $column)
                ));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
                try {
                    $table->unique('provider');
                } catch (\Throwable) {
                }
            });
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
        Schema::dropIfExists('tenants');
    }

    public function ensureTenantSchema(): void
    {
        if (! Schema::hasTable('tenants')) {
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
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('role')->constrained('tenants')->nullOnDelete();
            });
        }

        if (Schema::hasTable('media_storage_settings')) {
            if (! Schema::hasColumn('media_storage_settings', 'tenant_id')) {
                Schema::table('media_storage_settings', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                });
            }
            if (! Schema::hasColumn('media_storage_settings', 'tenant_scope')) {
                Schema::table('media_storage_settings', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_scope')->default(0)->after('tenant_id');
                });
            }
            $this->ensureForeignKey('media_storage_settings', 'tenant_id', 'tenants');
            $this->dropProviderOnlyUnique();
            $this->ensureUniqueIndex('media_storage_settings', ['tenant_scope', 'provider']);
        }

        if (Schema::hasTable('translation_api_keys')) {
            if (! Schema::hasColumn('translation_api_keys', 'tenant_id')) {
                Schema::table('translation_api_keys', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                });
            }
            if (! Schema::hasColumn('translation_api_keys', 'tenant_scope')) {
                Schema::table('translation_api_keys', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_scope')->default(0)->after('tenant_id');
                });
            }
            $this->ensureIndex('translation_api_keys', ['tenant_scope', 'provider']);
            $this->ensureForeignKey('translation_api_keys', 'tenant_id', 'tenants');
        }

        if (Schema::hasTable('holiday_entries') && ! Schema::hasColumn('holiday_entries', 'tenant_id')) {
            Schema::table('holiday_entries', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained('tenants')->nullOnDelete();
                $table->index(['tenant_id', 'date']);
            });
        }

        if (Schema::hasTable('weekday_rules') && ! Schema::hasColumn('weekday_rules', 'tenant_id')) {
            Schema::table('weekday_rules', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            });
        }
    }

    private function dropProviderOnlyUnique(): void
    {
        if (! Schema::hasTable('media_storage_settings')) {
            return;
        }

        // インデックス名は環境で違うので、provider 単独の unique を一覧から探す
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

    /** @param  list<string>  $columns */
    private function ensureUniqueIndex(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === $columns) {
                return;
            }
        }
        Schema::table($table, function (Blueprint $blueprint) use ($columns) {
            $blueprint->unique($columns);
        });
    }

    /** @param  list<string>  $columns */
    private function ensureIndex(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return;
            }
        }
        Schema::table($table, function (Blueprint $blueprint) use ($columns) {
            $blueprint->index($columns);
        });
    }

    private function ensureForeignKey(string $table, string $column, string $references): void
    {
        if (! Schema::hasColumn($table, $column) || ! Schema::hasTable($references)) {
            return;
        }
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['columns'] ?? []) === [$column]) {
                return;
            }
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references) {
                $blueprint->foreign($column)->references('id')->on($references)->nullOnDelete();
            });
        } catch (\Throwable) {
            // 既存データやエンジン差で FK が張れない場合は列だけ残す
        }
    }
};
