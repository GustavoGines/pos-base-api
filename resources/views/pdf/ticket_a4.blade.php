<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante #{{ str_pad($sale->id, 8, '0', STR_PAD_LEFT) }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 14px;
            margin: 0;
            padding: 0;
        }
        @page {
            margin: 30px;
        }
        tr {
            page-break-inside: avoid;
        }
        #page-number {
            position: fixed;
            bottom: -15px;
            right: 0;
            font-size: 10px;
            color: #777;
        }
        #page-number:after {
            content: "Página " counter(page);
        }
        .container {
            width: 100%;
            padding: 20px;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header table {
            width: 100%;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        .invoice-details {
            text-align: right;
            font-size: 14px;
        }
        .invoice-details strong {
            display: inline-block;
            width: 80px;
        }
        .client-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .table-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table-items th {
            background-color: #34495e;
            color: #ffffff;
            padding: 10px;
            text-align: left;
            font-size: 13px;
        }
        .table-items td {
            padding: 10px;
            border-bottom: 1px solid #eeeeee;
            font-size: 13px;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .footer-totals {
            width: 100%;
            margin-top: 20px;
        }
        .footer-totals table {
            width: 100%;
            border-collapse: collapse;
        }
        .footer-totals td {
            padding: 8px 10px;
            font-size: 14px;
        }
        .total-row td {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #333;
        }
        .payment-method {
            margin-top: 50px;
            font-size: 12px;
            color: #7f8c8d;
            clear: both;
            border-top: 1px dashed #ccc;
            padding-top: 10px;
            text-align: center;
        }
        .watermark {
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 70px;
            color: rgba(0, 0, 0, 0.05);
            font-weight: bold;
            z-index: -1;
            text-align: center;
            line-height: 1.2;
        }
    </style>
</head>
<body>
    <div id="page-number"></div>
    <div class="container">
        <div class="watermark">COMPROBANTE<br>NO FISCAL</div>
        
        <div class="header">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <div class="company-name">{{ $settings['company_name'] ?? 'Mi Negocio' }}</div>
                        @if(!empty($settings['address']))
                            <div>{{ $settings['address'] }}</div>
                        @endif
                        @if(!empty($settings['phone']))
                            <div>Tel: {{ $settings['phone'] }}</div>
                        @endif
                        @if(!empty($settings['tax_id']))
                            <div>CUIT: {{ $settings['tax_id'] }}</div>
                        @endif
                    </td>
                    <td class="invoice-details" style="vertical-align: top;">
                        <div style="font-size: 18px; font-weight: bold; margin-bottom: 10px;">COMPROBANTE DE VENTA</div>
                        <div><strong>Número:</strong> {{ str_pad($sale->id, 8, '0', STR_PAD_LEFT) }}</div>
                        <div><strong>Fecha:</strong> {{ $sale->created_at->format('d/m/Y H:i') }}</div>
                        @if($sale->user)
                            @if($sale->cashier && $sale->user->id !== $sale->cashier->id)
                                <div><strong>Generó:</strong> {{ strtoupper($sale->user->name) }}</div>
                                <div><strong>Cobró:</strong> {{ strtoupper($sale->cashier->name) }}</div>
                            @else
                                <div><strong>Cajero:</strong> {{ strtoupper($sale->user->name) }}</div>
                            @endif
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        @if($sale->customer)
        <div class="client-section">
            <table style="width: 100%;">
                <tr>
                    <td><strong>Cliente:</strong> {{ $sale->customer->name }}</td>
                    @if($sale->customer->tax_id)
                        <td><strong>CUIT/DNI:</strong> {{ $sale->customer->tax_id }}</td>
                    @endif
                    @if($sale->customer->phone)
                        <td><strong>Tel:</strong> {{ $sale->customer->phone }}</td>
                    @endif
                </tr>
            </table>
        </div>
        @endif

        <table class="table-items">
            <thead>
                <tr>
                    <th style="width: 10%;" class="text-center">Cant.</th>
                    <th style="width: 50%;">Descripción</th>
                    <th style="width: 20%;" class="text-right">Precio Unit.</th>
                    <th style="width: 20%;" class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                <tr>
                    <td class="text-center">
                        {{ fmod($item->quantity, 1) == 0 ? number_format($item->quantity, 0) : number_format($item->quantity, 3) }}
                    </td>
                    <td>{{ $item->product_name }}</td>
                    <td class="text-right">${{ number_format($item->unit_price, 2, ',', '.') }}</td>
                    <td class="text-right">${{ number_format($item->subtotal, 2, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <table style="width: 100%; margin-top: 20px;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                    <div style="margin-bottom: 5px;">
                        <strong>Información de Pago</strong>
                    </div>
                    <table style="width: 100%; font-size: 13px; color: #555;">
                        @if($sale->payments->count() > 0)
                            @foreach($sale->payments as $payment)
                            <tr>
                                <td style="padding: 3px 0; vertical-align: top;">• {{ strtoupper($payment->paymentMethod->name) }}</td>
                                <td class="text-right" style="padding: 3px 0; vertical-align: top;">
                                    ${{ number_format($payment->total_amount, 2, ',', '.') }}
                                    @if(isset($payment->surcharge_amount) && $payment->surcharge_amount > 0)
                                        <br><span style="font-size: 11px; color: #888;">(Incluye ${{ number_format($payment->surcharge_amount, 2, ',', '.') }} recargo)</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        @else
                            <tr>
                                <td style="padding: 2px 0;">• PAGO ACORDADO</td>
                                <td class="text-right" style="padding: 2px 0;">${{ number_format($sale->total + $sale->total_surcharge, 2, ',', '.') }}</td>
                            </tr>
                        @endif
                    </table>

                    @if(isset($sale->tendered_amount) && $sale->tendered_amount > 0)
                    <div style="margin-top: 15px; color: #555; font-size: 13px; border-top: 1px dashed #ccc; padding-top: 8px;">
                        <table style="width: 100%;">
                            <tr>
                                <td style="padding: 2px 0;">Abonó con:</td>
                                <td class="text-right" style="padding: 2px 0;">${{ number_format($sale->tendered_amount, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 2px 0;">Vuelto:</td>
                                <td class="text-right" style="padding: 2px 0;">${{ number_format($sale->change_amount, 2, ',', '.') }}</td>
                            </tr>
                        </table>
                    </div>
                    @endif
                </td>

                <td style="width: 50%; vertical-align: bottom;">
                    <div class="footer-totals" style="margin-top: 0;">
                        <table>
                            @if($sale->total_surcharge > 0)
                            <tr>
                                <td>Subtotal:</td>
                                <td class="text-right">${{ number_format($sale->total, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Recargos/Intereses:</td>
                                <td class="text-right">${{ number_format($sale->total_surcharge, 2, ',', '.') }}</td>
                            </tr>
                            @endif
                            <tr class="total-row">
                                <td style="padding-top: 15px;">TOTAL:</td>
                                <td class="text-right" style="padding-top: 15px;">${{ number_format($sale->total + $sale->total_surcharge, 2, ',', '.') }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="payment-method">
            <i>{{ $settings['receipt_footer_message'] ?? 'Documento no válido como factura. Gracias por su compra.' }}</i>
        </div>
    </div>
</body>
</html>
