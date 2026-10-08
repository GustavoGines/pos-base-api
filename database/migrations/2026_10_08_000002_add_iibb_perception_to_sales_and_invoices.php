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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('iibb_perception_amount', 12, 2)->default(0.00)->after('total_surcharge')
                ->comment('Monto total percibido por Ingresos Brutos en la venta');
            $table->decimal('iibb_perception_rate', 5, 2)->nullable()->default(null)->after('iibb_perception_amount')
                ->comment('Alícuota porcentual de IIBB aplicada a la venta');
        });

        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->decimal('tribute_amount', 12, 2)->default(0.00)->after('iva_amount')
                ->comment('Monto total de otros tributos (ImpTrib en WSFEv1)');
            $table->json('tributes_breakdown')->nullable()->after('iva_breakdown')
                ->comment('Desglose de tributos (Id, Desc, BaseImp, Alic, Importe)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn(['tribute_amount', 'tributes_breakdown']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['iibb_perception_amount', 'iibb_perception_rate']);
        });
    }
};
