<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega description y vertical_type (opcional) a rubros con guardas idempotentes para OTA.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rubros')) {
            return;
        }

        if (! Schema::hasColumn('rubros', 'description')) {
            Schema::table('rubros', function (Blueprint $table) {
                $table->string('description', 255)->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('rubros', 'vertical_type')) {
            Schema::table('rubros', function (Blueprint $table) {
                $table->string('vertical_type', 40)->nullable()->after('description');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('rubros')) {
            return;
        }

        $dropCols = [];
        if (Schema::hasColumn('rubros', 'vertical_type')) {
            $dropCols[] = 'vertical_type';
        }
        if (Schema::hasColumn('rubros', 'description')) {
            $dropCols[] = 'description';
        }

        if ($dropCols !== []) {
            Schema::table('rubros', function (Blueprint $table) use ($dropCols) {
                $table->dropColumn($dropCols);
            });
        }
    }
};
