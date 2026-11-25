<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Sanear strings vacíos o con solo espacios a NULL
        DB::table('customers')
            ->whereNotNull('document_number')
            ->whereRaw("TRIM(document_number) = ''")
            ->update(['document_number' => null]);

        // 2. Normalizar clientes sin documento y Consumidor Final a DocTipo 99
        DB::table('customers')
            ->whereNull('document_number')
            ->where('tax_condition', 'consumidor_final')
            ->update(['document_type' => 99]);

        // 3. Coherencia bidireccional: si tiene documento cargado pero estaba en 99, inferir 80 u 96
        $inconsistent = DB::table('customers')
            ->whereNotNull('document_number')
            ->where('document_type', 99)
            ->get(['id', 'document_number', 'tax_condition', 'applies_iibb_perception']);

        foreach ($inconsistent as $row) {
            $digits = preg_replace('/\D/', '', (string) $row->document_number);
            $requiresCuit = ($row->tax_condition !== 'consumidor_final') || (bool) $row->applies_iibb_perception;
            $inferred = ($requiresCuit || strlen($digits) === 11) ? 80 : 96;

            DB::table('customers')
                ->where('id', $row->id)
                ->update(['document_type' => $inferred]);
        }

        // 4. Cambiar default de document_type a 99 y agregar índices simples de búsqueda
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('document_type')->default(99)->change();
            $table->index('document_number', 'customers_document_number_index');
            $table->index('phone', 'customers_phone_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_document_number_index');
            $table->dropIndex('customers_phone_index');
            $table->unsignedSmallInteger('document_type')->default(96)->change();
        });
    }
};
