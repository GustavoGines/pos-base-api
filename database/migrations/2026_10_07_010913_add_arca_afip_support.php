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
        // 1. Ampliar customers con campos impositivos obligatorios de AFIP
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('document_type')->default(96)->after('document_number')
                ->comment('AFIP Tipo Doc: 80=CUIT, 86=CUIL, 96=DNI, 99=Consumidor Final');
            $table->string('tax_condition', 50)->default('consumidor_final')->after('document_type')
                ->comment('responsable_inscripto, monotributo, consumidor_final, exento');
            $table->string('fiscal_address', 255)->nullable()->after('tax_condition');
        });

        // 2. Añadir alícuota de IVA a la tabla products
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('iva_rate', 5, 2)->default(21.00)->after('selling_price')
                ->comment('Alícuota IVA porcentual: 0.00, 10.50, 21.00, 27.00');
        });

        // 3. Añadir desglose de IVA y base neta a sale_items
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('iva_rate', 5, 2)->default(21.00)->after('unit_price');
            $table->decimal('iva_amount', 12, 2)->default(0.00)->after('iva_rate');
            $table->decimal('net_amount', 12, 2)->default(0.00)->after('iva_amount');
        });

        // 4. Añadir estado de facturación a sales
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('invoice_status', ['none', 'pending', 'invoiced', 'failed'])
                  ->default('none')
                  ->after('status')
                  ->index();
        });

        // 5. Crear tabla de comprobantes fiscales electrónicos (ARCA / AFIP WSFEv1)
        // Se utiliza restrictOnDelete() para blindar legalmente el historial tributario y auditoría AFIP (RG 1415)
        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            
            // Datos del Comprobante
            $table->unsignedSmallInteger('voucher_type')
                ->comment('AFIP Cbte: 1=Factura A, 6=Factura B, 11=Factura C, 3=NC A, 8=NC B, 13=NC C');
            $table->char('voucher_letter', 1); // 'A', 'B', 'C'
            $table->unsignedSmallInteger('point_of_sale')->comment('Punto de Venta AFIP (PtoVta)');
            $table->unsignedBigInteger('voucher_number')->comment('Número correlativo de comprobante');
            
            // CAE y Vencimiento
            $table->string('cae', 14)->index();
            $table->date('cae_expiration');
            
            // Receptor del comprobante
            $table->unsignedSmallInteger('doc_type');
            $table->string('doc_number', 20);
            $table->string('receiver_name', 150)->nullable();
            $table->string('receiver_address', 255)->nullable();
            $table->string('receiver_tax_condition', 50)->nullable();
            
            // Importes monetarios desagregados (AFIP RG 1415 / RG 4291)
            $table->decimal('net_amount', 12, 2)->default(0.00);    // ImpNeto
            $table->decimal('iva_amount', 12, 2)->default(0.00);    // ImpIVA
            $table->decimal('exempt_amount', 12, 2)->default(0.00); // ImpOpEx
            $table->decimal('untaxed_amount', 12, 2)->default(0.00);// ImpTotConc
            $table->decimal('total_amount', 12, 2);                 // ImpTotal
            
            // Array JSON con desglose de alícuotas (Id, BaseImp, Importe)
            $table->json('iva_breakdown')->nullable();
            
            // URL y Payload Oficial del QR AFIP (RG 4892/2020)
            $table->text('qr_data')->nullable();
            
            // Auditoría y trazabilidad del Web Service
            $table->enum('status', ['authorized', 'rejected', 'failed', 'cancelled'])->default('authorized');
            $table->json('afip_request')->nullable();
            $table->json('afip_response')->nullable();
            $table->text('error_message')->nullable();
            
            $table->dateTime('issued_at');
            $table->timestamps();

            // Clave única compuesta para evitar duplicidad de comprobantes fiscales
            $table->unique(['voucher_type', 'point_of_sale', 'voucher_number'], 'uk_voucher_identity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electronic_invoices');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('invoice_status');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['iva_rate', 'iva_amount', 'net_amount']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('iva_rate');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['document_type', 'tax_condition', 'fiscal_address']);
        });
    }
};
