<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('applies_iibb_perception')->default(false)->after('is_internal_account')
                ->comment('Indica si el cliente está sujeto a percepción de Ingresos Brutos (IIBB)');
            $table->decimal('iibb_perception_rate', 5, 2)->nullable()->default(null)->after('applies_iibb_perception')
                ->comment('Alícuota porcentual personalizada de IIBB (ej. 3.00)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['applies_iibb_perception', 'iibb_perception_rate']);
        });
    }
};
