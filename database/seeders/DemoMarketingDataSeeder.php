<?php

namespace Database\Seeders;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Supplier;
use App\Models\ThirdPartyCheck;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoMarketingDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'admin@pos.com')->first() ?? User::first();
        $userId = $user?->id ?? 1;

        $activeShift = CashShift::where('status', 'open')->latest()->first() ?? CashShift::latest()->first();
        $shiftId = $activeShift?->id;

        // ─────────────────────────────────────────────────────────────────
        // 1. CATEGORÍAS DE GASTOS
        // ─────────────────────────────────────────────────────────────────
        $catServicios = ExpenseCategory::firstOrCreate(
            ['name' => 'Servicios (Luz, Internet, Gas)'],
            ['is_active' => true]
        );
        $catLimpieza = ExpenseCategory::firstOrCreate(
            ['name' => 'Limpieza e Higiene del Local'],
            ['is_active' => true]
        );
        $catInsumos = ExpenseCategory::firstOrCreate(
            ['name' => 'Insumos de Mostrador y Papelería'],
            ['is_active' => true]
        );
        $catFletes = ExpenseCategory::firstOrCreate(
            ['name' => 'Fletes, Viáticos y Cadetería'],
            ['is_active' => true]
        );
        $catRefrigerio = ExpenseCategory::firstOrCreate(
            ['name' => 'Refrigerio del Personal'],
            ['is_active' => true]
        );
        $catMantenimiento = ExpenseCategory::firstOrCreate(
            ['name' => 'Mantenimiento y Reparaciones'],
            ['is_active' => true]
        );

        // ─────────────────────────────────────────────────────────────────
        // 2. MOVIMIENTOS DE CAJA (GASTOS E INGRESOS)
        // ─────────────────────────────────────────────────────────────────
        if ($shiftId) {
            $movements = [
                [
                    'amount' => 14500.00,
                    'type' => 'expense',
                    'category' => 'Insumos',
                    'expense_category_id' => $catInsumos->id,
                    'description' => 'Compra rollos papel térmico 80mm (pack x10) y bolsas',
                    'receipt_number' => 'FC-B 0003-00041289',
                ],
                [
                    'amount' => 6200.00,
                    'type' => 'expense',
                    'category' => 'Fletes',
                    'expense_category_id' => $catFletes->id,
                    'description' => 'Envío cadetería entrega urgente cliente Supermercado',
                    'receipt_number' => 'REC-00124',
                ],
                [
                    'amount' => 8400.00,
                    'type' => 'expense',
                    'category' => 'Limpieza',
                    'expense_category_id' => $catLimpieza->id,
                    'description' => 'Lavandina, desinfectante y toallas de papel mostrador',
                    'receipt_number' => 'TCK-8812',
                ],
                [
                    'amount' => 5200.00,
                    'type' => 'expense',
                    'category' => 'Refrigerio',
                    'expense_category_id' => $catRefrigerio->id,
                    'description' => 'Café, azúcar y galletitas personal turno mañana',
                    'receipt_number' => 'TCK-9041',
                ],
                [
                    'amount' => 50000.00,
                    'type' => 'deposit',
                    'category' => 'Cambio',
                    'expense_category_id' => null,
                    'description' => 'Aporte de cambio inicial en billetes de 500 y 1000',
                    'receipt_number' => null,
                ],
                [
                    'amount' => 80000.00,
                    'type' => 'withdrawal',
                    'category' => 'Retiro',
                    'expense_category_id' => null,
                    'description' => 'Retiro de efectivo recaudado a caja fuerte tesorería',
                    'receipt_number' => 'RET-004',
                ],
            ];

            foreach ($movements as $mov) {
                CashMovement::firstOrCreate(
                    [
                        'cash_shift_id' => $shiftId,
                        'description' => $mov['description'],
                    ],
                    [
                        'user_id' => $userId,
                        'authorized_by' => $userId,
                        'amount' => $mov['amount'],
                        'payment_method' => 'cash',
                        'type' => $mov['type'],
                        'category' => $mov['category'],
                        'expense_category_id' => $mov['expense_category_id'],
                        'receipt_number' => $mov['receipt_number'],
                    ]
                );
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 3. PROVEEDORES REALISTAS
        // ─────────────────────────────────────────────────────────────────
        $suppliersData = [
            [
                'name' => 'Distribuidora Bebidas del Norte S.A.',
                'cuit' => '30714589234',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Marcos Varela',
                'phone' => '3704-589223',
                'email' => 'ventas@bebidasdelnorte.com.ar',
                'address' => 'Av. Independencia 2450, Formosa',
                'balance' => 1250000.00,
            ],
            [
                'name' => 'Stanley Argentina Importaciones SRL',
                'cuit' => '30689451238',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Florencia Peña',
                'phone' => '11-5522-8899',
                'email' => 'pedidos@stanleyarg.com.ar',
                'address' => 'Panamericana Km 38.5, Buenos Aires',
                'balance' => 450000.00,
            ],
            [
                'name' => 'Lácteos y Alimentos La Campiña',
                'cuit' => '30612345871',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Juan Carlos Rossi',
                'phone' => '3704-421188',
                'email' => 'distribucion@lacampina.com.ar',
                'address' => 'Ruta Nac. 11 Km 1140',
                'balance' => 320000.00,
            ],
            [
                'name' => 'Ferretería Industrial Sudamericana',
                'cuit' => '30709823145',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Esteban Quispe',
                'phone' => '3704-456992',
                'email' => 'mayorista@sudamericanaferr.com',
                'address' => 'Av. Gutnisky 3120, Formosa',
                'balance' => 890000.00,
            ],
            [
                'name' => 'Papelera & Embalajes del Plata',
                'cuit' => '30711234906',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Carla Domínguez',
                'phone' => '11-4300-8811',
                'email' => 'ventas@papeleradelplata.com',
                'address' => 'Av. Belgrano 1850, CABA',
                'balance' => 0.00,
            ],
            [
                'name' => 'Golosinas y Snacks San Cayetano',
                'cuit' => '20289456129',
                'tax_category' => 'Monotributo',
                'contact_name' => 'Roberto Gómez',
                'phone' => '3704-466331',
                'email' => 'sancayetanomayorista@gmail.com',
                'address' => 'San Martín 840, Formosa',
                'balance' => 185000.00,
            ],
            [
                'name' => 'Química e Higiene Institucional SRL',
                'cuit' => '30715523197',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Silvia Navarro',
                'phone' => '3704-488220',
                'email' => 'info@quimicanavarro.com.ar',
                'address' => 'España 1245, Formosa',
                'balance' => 95000.00,
            ],
        ];

        $createdSuppliers = [];
        foreach ($suppliersData as $sup) {
            $createdSuppliers[] = Supplier::firstOrCreate(
                ['cuit' => $sup['cuit']],
                [
                    'name' => $sup['name'],
                    'tax_category' => $sup['tax_category'],
                    'contact_name' => $sup['contact_name'],
                    'phone' => $sup['phone'],
                    'email' => $sup['email'],
                    'address' => $sup['address'],
                    'balance' => $sup['balance'],
                    'is_active' => true,
                ]
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // 4. CLIENTES Y CUENTAS CORRIENTES
        // ─────────────────────────────────────────────────────────────────
        $customersData = [
            [
                'name' => 'Supermercado Los Primos SRL',
                'phone' => '3704-455889',
                'document_number' => '30712345679',
                'document_type' => 80, // CUIT
                'tax_condition' => 'responsable_inscripto',
                'delivery_address' => 'Av. 25 de Mayo 1420',
                'fiscal_address' => 'Av. 25 de Mayo 1420, Formosa',
                'credit_limit' => 1500000.00,
                'balance' => 385000.00,
            ],
            [
                'name' => 'Valentina Morales',
                'phone' => '3704-422334',
                'document_number' => '38452190',
                'document_type' => 96, // DNI
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Barrio San Francisco Mza 12 Casa 4',
                'fiscal_address' => 'Barrio San Francisco Mza 12 Casa 4',
                'credit_limit' => 150000.00,
                'balance' => 45200.00,
            ],
            [
                'name' => 'Martín Rodríguez (Kiosco El Paso)',
                'phone' => '3704-466778',
                'document_number' => '20321148904',
                'document_type' => 80, // CUIT
                'tax_condition' => 'monotributo',
                'delivery_address' => 'Rivadavia 650',
                'fiscal_address' => 'Rivadavia 650, Formosa',
                'credit_limit' => 300000.00,
                'balance' => 128500.00,
            ],
            [
                'name' => 'Construcciones & Servicios del Norte SA',
                'phone' => '11-4899-3322',
                'document_number' => '30659821432',
                'document_type' => 80, // CUIT
                'tax_condition' => 'responsable_inscripto',
                'delivery_address' => 'Parque Industrial Lote 18',
                'fiscal_address' => 'Parque Industrial Lote 18, Formosa',
                'credit_limit' => 2500000.00,
                'balance' => 740000.00,
            ],
            [
                'name' => 'Farmacia San José',
                'phone' => '3704-443221',
                'document_number' => '30708912341',
                'document_type' => 80, // CUIT
                'tax_condition' => 'responsable_inscripto',
                'delivery_address' => 'Av. Italia 890',
                'fiscal_address' => 'Av. Italia 890, Formosa',
                'credit_limit' => 800000.00,
                'balance' => 0.00,
            ],
            [
                'name' => 'Mariana Belén Gómez',
                'phone' => '3704-499112',
                'document_number' => '40112983',
                'document_type' => 96, // DNI
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Fontana 1230 Piso 2',
                'fiscal_address' => 'Fontana 1230 Piso 2, Formosa',
                'credit_limit' => 100000.00,
                'balance' => 0.00,
            ],
            [
                'name' => 'Lucas Benitez',
                'phone' => '3704-433556',
                'document_number' => '35892401',
                'document_type' => 96, // DNI
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Av. González Lelong 540',
                'fiscal_address' => 'Av. González Lelong 540, Formosa',
                'credit_limit' => 120000.00,
                'balance' => 18400.00,
            ],
            [
                'name' => 'Colegio Privado San Martín',
                'phone' => '3704-441990',
                'document_number' => '30642189038',
                'document_type' => 80, // CUIT
                'tax_condition' => 'exento',
                'delivery_address' => 'Mitre 340',
                'fiscal_address' => 'Mitre 340, Formosa',
                'credit_limit' => 500000.00,
                'balance' => 95000.00,
            ],
        ];

        $createdCustomers = [];
        foreach ($customersData as $cust) {
            $customer = Customer::firstOrCreate(
                ['document_number' => $cust['document_number']],
                [
                    'name' => $cust['name'],
                    'phone' => $cust['phone'],
                    'document_type' => $cust['document_type'],
                    'tax_condition' => $cust['tax_condition'],
                    'delivery_address' => $cust['delivery_address'],
                    'fiscal_address' => $cust['fiscal_address'],
                    'credit_limit' => $cust['credit_limit'],
                    'balance' => $cust['balance'],
                    'is_active' => true,
                    'is_internal_account' => false,
                    'default_price_tier' => 'base',
                ]
            );
            $createdCustomers[] = $customer;

            // Transacciones de cuenta corriente de muestra
            if ($cust['balance'] > 0 && CustomerTransaction::where('customer_id', $customer->id)->count() === 0) {
                CustomerTransaction::create([
                    'customer_id' => $customer->id,
                    'user_id' => $userId,
                    'type' => 'charge',
                    'amount' => $cust['balance'] + 50000,
                    'balance_after' => $cust['balance'] + 50000,
                    'description' => 'Compra en Mostrador - Comprobante VTA-0089',
                    'created_at' => Carbon::now()->subDays(5),
                    'payment_method' => 'current_account',
                ]);

                CustomerTransaction::create([
                    'customer_id' => $customer->id,
                    'user_id' => $userId,
                    'type' => 'payment',
                    'amount' => 50000,
                    'balance_after' => $cust['balance'],
                    'description' => 'Entrega de Pago Parcial Efectivo - Recibo REC-0042',
                    'created_at' => Carbon::now()->subDays(1),
                    'payment_method' => 'cash',
                ]);
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 5. CHEQUES DE TERCEROS (ACTIVOS, SALIENTES Y CONFLICTOS)
        // ─────────────────────────────────────────────────────────────────
        $checksData = [
            [
                'bank_name' => 'Banco Galicia',
                'check_number' => '44892104',
                'amount' => 350000.00,
                'issue_date' => Carbon::now()->subDays(10)->toDateString(),
                'payment_date' => Carbon::now()->addDays(5)->toDateString(),
                'issuer_name' => 'Agroservicios del Litoral SRL',
                'issuer_cuit' => '30711928374',
                'status' => 'in_wallet',
                'endorsement_note' => null,
                'customer_id' => $createdCustomers[0]->id ?? null,
                'supplier_id' => null,
            ],
            [
                'bank_name' => 'Banco Santander',
                'check_number' => '11982344',
                'amount' => 520000.00,
                'issue_date' => Carbon::now()->subDays(5)->toDateString(),
                'payment_date' => Carbon::now()->addDays(12)->toDateString(),
                'issuer_name' => 'Constructora Río Bermejo',
                'issuer_cuit' => '30659821432',
                'status' => 'in_wallet',
                'endorsement_note' => null,
                'customer_id' => $createdCustomers[3]->id ?? null,
                'supplier_id' => null,
            ],
            [
                'bank_name' => 'Banco de la Nación Argentina',
                'check_number' => '88231940',
                'amount' => 180000.00,
                'issue_date' => Carbon::now()->subDays(15)->toDateString(),
                'payment_date' => Carbon::now()->addDays(2)->toDateString(),
                'issuer_name' => 'Transportes Don José SRL',
                'issuer_cuit' => '30718829104',
                'status' => 'in_wallet',
                'endorsement_note' => null,
                'customer_id' => $createdCustomers[2]->id ?? null,
                'supplier_id' => null,
            ],
            [
                'bank_name' => 'BBVA Francés',
                'check_number' => '66109234',
                'amount' => 420000.00,
                'issue_date' => Carbon::now()->subDays(3)->toDateString(),
                'payment_date' => Carbon::now()->addDays(20)->toDateString(),
                'issuer_name' => 'San Martín Eventos y Catering',
                'issuer_cuit' => '30704455661',
                'status' => 'in_wallet',
                'endorsement_note' => null,
                'customer_id' => $createdCustomers[0]->id ?? null,
                'supplier_id' => null,
            ],
            [
                'bank_name' => 'Banco Macro',
                'check_number' => '33981245',
                'amount' => 95000.00,
                'issue_date' => Carbon::now()->subDays(25)->toDateString(),
                'payment_date' => Carbon::now()->addDays(1)->toDateString(),
                'issuer_name' => 'Kiosco Central Formosa',
                'issuer_cuit' => '20321148904',
                'status' => 'in_wallet',
                'endorsement_note' => null,
                'customer_id' => $createdCustomers[2]->id ?? null,
                'supplier_id' => null,
            ],
            [
                'bank_name' => 'Banco Ciudad',
                'check_number' => '77123901',
                'amount' => 650000.00,
                'issue_date' => Carbon::now()->subDays(20)->toDateString(),
                'payment_date' => Carbon::now()->addDays(10)->toDateString(),
                'issuer_name' => 'Servicios Gastronómicos del Norte',
                'issuer_cuit' => '30719948215',
                'status' => 'endorsed',
                'endorsement_note' => 'Endosado a Stanley Argentina - Pago Factura FC-A 0001-000421',
                'customer_id' => $createdCustomers[0]->id ?? null,
                'supplier_id' => $createdSuppliers[1]->id ?? null,
            ],
            [
                'bank_name' => 'Banco Santander',
                'check_number' => '12093841',
                'amount' => 85000.00,
                'issue_date' => Carbon::now()->subDays(30)->toDateString(),
                'payment_date' => Carbon::now()->subDays(5)->toDateString(),
                'issuer_name' => 'Distribuciones Integrales SRL',
                'issuer_cuit' => '30713344558',
                'status' => 'rejected',
                'endorsement_note' => 'Rechazado sin fondos en cámara compensadora - Contactar cliente',
                'customer_id' => $createdCustomers[1]->id ?? null,
                'supplier_id' => null,
            ],
        ];

        foreach ($checksData as $chk) {
            ThirdPartyCheck::firstOrCreate(
                ['check_number' => $chk['check_number']],
                array_merge($chk, ['cash_shift_id' => $shiftId])
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // 6. PRESUPUESTOS (QUOTES)
        // ─────────────────────────────────────────────────────────────────
        $sampleProducts = Product::take(10)->get();
        if ($sampleProducts->count() >= 3) {
            $p1 = $sampleProducts[0];
            $p2 = $sampleProducts[1];
            $p3 = $sampleProducts[2];

            $quotesData = [
                [
                    'quote_number' => 'PRES-0002',
                    'status' => 'pending',
                    'customer_name' => 'CrossFit Central Formosa',
                    'customer_phone' => '3704-551122',
                    'valid_until' => Carbon::now()->addDays(15)->toDateString(),
                    'notes' => 'Presupuesto por equipamiento para alumnos - Válido 15 días con precio congelado.',
                    'price_list' => 'base',
                    'items' => [
                        ['product' => $p1, 'quantity' => 5],
                        ['product' => $p2, 'quantity' => 2],
                    ],
                ],
                [
                    'quote_number' => 'PRES-0003',
                    'status' => 'approved',
                    'customer_name' => 'Distribuidora San Martín',
                    'customer_phone' => '3704-448899',
                    'valid_until' => Carbon::now()->addDays(7)->toDateString(),
                    'notes' => 'Aprobado por gerencia de compras. Entrega pactada para próxima semana.',
                    'price_list' => 'mayorista',
                    'items' => [
                        ['product' => $p2, 'quantity' => 4],
                        ['product' => $p3, 'quantity' => 6],
                    ],
                ],
                [
                    'quote_number' => 'PRES-0004',
                    'status' => 'pending',
                    'customer_name' => 'Martín Benitez (Regalos Fin de Año)',
                    'customer_phone' => '3704-223311',
                    'valid_until' => Carbon::now()->addDays(10)->toDateString(),
                    'notes' => 'Cotización combo termos y accesorios. Solicita factura A.',
                    'price_list' => 'base',
                    'items' => [
                        ['product' => $p1, 'quantity' => 3],
                        ['product' => $p3, 'quantity' => 3],
                    ],
                ],
                [
                    'quote_number' => 'PRES-0005',
                    'status' => 'expired',
                    'customer_name' => 'Kiosco El Triángulo',
                    'customer_phone' => '3704-998877',
                    'valid_until' => Carbon::now()->subDays(3)->toDateString(),
                    'notes' => 'Cotización vencida sin confirmación del cliente.',
                    'price_list' => 'base',
                    'items' => [
                        ['product' => $p1, 'quantity' => 2],
                    ],
                ],
                [
                    'quote_number' => 'PRES-0006',
                    'status' => 'approved',
                    'customer_name' => 'Constructora del Litoral SRL',
                    'customer_phone' => '11-4455-6677',
                    'valid_until' => Carbon::now()->addDays(20)->toDateString(),
                    'notes' => 'Presupuesto mayorista cerrado. Pendiente de facturar y coordinar logística.',
                    'price_list' => 'mayorista',
                    'items' => [
                        ['product' => $p2, 'quantity' => 5],
                    ],
                ],
            ];

            foreach ($quotesData as $qData) {
                if (Quote::where('quote_number', $qData['quote_number'])->exists()) {
                    continue;
                }

                $subtotal = 0;
                $itemsToCreate = [];
                foreach ($qData['items'] as $item) {
                    $prod = $item['product'];
                    $qty = $item['quantity'];
                    $price = (float) $prod->selling_price;
                    $itemSubtotal = $price * $qty;
                    $subtotal += $itemSubtotal;

                    $itemsToCreate[] = [
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'unit_price' => $price,
                        'quantity' => $qty,
                        'subtotal' => $itemSubtotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $quote = Quote::create([
                    'quote_number' => $qData['quote_number'],
                    'status' => $qData['status'],
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                    'notes' => $qData['notes'],
                    'customer_name' => $qData['customer_name'],
                    'customer_phone' => $qData['customer_phone'],
                    'valid_until' => $qData['valid_until'],
                    'price_list' => $qData['price_list'],
                    'user_id' => $userId,
                ]);

                foreach ($itemsToCreate as $it) {
                    $it['quote_id'] = $quote->id;
                    QuoteItem::create($it);
                }
            }
        }
    }
}
