<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Libro IVA Ventas</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5px;
            color: #1e293b;
            background: #ffffff;
        }
        .header {
            background: #1e3a5f;
            color: #ffffff;
            padding: 16px 22px 14px;
            border-radius: 0 0 4px 4px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .brand-name {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }
        .brand-subtitle {
            font-size: 10px;
            color: #cbd5e1;
            margin-top: 2px;
        }
        .report-meta {
            text-align: right;
            font-size: 9.5px;
            color: #cbd5e1;
            line-height: 1.5;
        }
        .kpi-section {
            padding: 12px 22px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
        }
        .kpi-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 10px;
            vertical-align: top;
        }
        .kpi-label {
            font-size: 8.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .kpi-value {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .kpi-value.iva { color: #b45309; }
        .kpi-value.net { color: #1d4ed8; }
        .kpi-sub {
            font-size: 8px;
            color: #475569;
            margin-top: 3px;
        }
        .content {
            padding: 14px 22px 20px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        table.data-table th {
            background: #1e293b;
            color: #ffffff;
            font-size: 8.2px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 6px 5px;
            text-align: left;
            border: 1px solid #1e293b;
        }
        table.data-table th.num, table.data-table td.num {
            text-align: right;
        }
        table.data-table td {
            padding: 5px 5px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 8.5px;
        }
        table.data-table tr:nth-child(even) td {
            background: #f8fafc;
        }
        table.data-table tr.total-row td {
            background: #e2e8f0;
            font-weight: 800;
            font-size: 9px;
            border-top: 2px solid #0f172a;
        }
        .nc-badge {
            color: #dc2626;
            font-weight: 700;
        }
        .footer {
            margin-top: 14px;
            text-align: right;
            font-size: 8px;
            color: #64748b;
        }
    </style>
</head>
<body>
    @php
        $summary = $data['summary'] ?? [];
        $vouchers = $data['vouchers'] ?? [];
        $fmt = fn($val) => '$' . number_format((float) $val, 2, ',', '.');
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="brand-name">LIBRO IVA VENTAS — SUBDIARIO FISCAL ARCA / AFIP</div>
                    <div class="brand-subtitle">
                        {{ $companyName ?: 'Comercio Responsable Inscripto' }}
                        @if(!empty($companyCuit)) · CUIT: {{ $companyCuit }} @endif
                    </div>
                </td>
                <td class="report-meta">
                    <div><strong>Período Fiscal:</strong> {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</div>
                    <div><strong>Generado:</strong> {{ $generatedAt }}</div>
                    <div><strong>Comprobantes Autorizados:</strong> {{ $summary['vouchers_count'] ?? count($vouchers) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="kpi-section">
        <table class="kpi-table">
            <tr>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Total Facturado Fiscal (Neto de NC)</div>
                    <div class="kpi-value">{{ $fmt($summary['net_fiscal_total'] ?? 0) }}</div>
                    <div class="kpi-sub">Facturas: {{ $fmt($summary['total_invoiced'] ?? 0) }} | NC: -{{ $fmt($summary['total_credit_notes'] ?? 0) }}</div>
                </td>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Total Neto Gravado (Base Imp.)</div>
                    <div class="kpi-value net">{{ $fmt($summary['total_net'] ?? 0) }}</div>
                    <div class="kpi-sub">21%: {{ $fmt($summary['net_21'] ?? 0) }} | 10.5%: {{ $fmt($summary['net_105'] ?? 0) }}</div>
                </td>
                <td class="kpi-card" width="34%">
                    <div class="kpi-label">IVA Débito Fiscal a Declarar (F. 2002)</div>
                    <div class="kpi-value iva">{{ $fmt($summary['total_iva'] ?? 0) }}</div>
                    <div class="kpi-sub">
                        IVA 21%: <strong>{{ $fmt($summary['iva_21'] ?? 0) }}</strong> ·
                        IVA 10.5%: <strong>{{ $fmt($summary['iva_105'] ?? 0) }}</strong> ·
                        IVA 27%: <strong>{{ $fmt($summary['iva_27'] ?? 0) }}</strong>
                    </div>
                </td>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Percepciones IIBB y Exentos</div>
                    <div class="kpi-value">{{ $fmt(($summary['total_tributes'] ?? 0) + ($summary['total_exempt'] ?? 0)) }}</div>
                    <div class="kpi-sub">Percep. IIBB: {{ $fmt($summary['total_tributes'] ?? 0) }} | Exento: {{ $fmt($summary['total_exempt'] ?? 0) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Comprobante</th>
                    <th>Número</th>
                    <th>Doc / CUIT</th>
                    <th>Cliente / Razón Social</th>
                    <th>Cond. IVA</th>
                    <th class="num">Neto 21%</th>
                    <th class="num">IVA 21%</th>
                    <th class="num">Neto 10.5%</th>
                    <th class="num">IVA 10.5%</th>
                    <th class="num">Neto 27%</th>
                    <th class="num">IVA 27%</th>
                    <th class="num">Exento</th>
                    <th class="num">Percep.</th>
                    <th class="num">Total</th>
                    <th>CAE</th>
                </tr>
            </thead>
            <tbody>
                @forelse($vouchers as $v)
                    <tr>
                        <td>{{ !empty($v['issued_at']) ? \Carbon\Carbon::parse($v['issued_at'])->format('d/m/Y') : '-' }}</td>
                        <td class="{{ ($v['kind'] ?? '') === 'credit_note' ? 'nc-badge' : '' }}">{{ $v['voucher_label'] ?? '' }}</td>
                        <td>{{ $v['formatted_number'] ?? '' }}</td>
                        <td>{{ $v['doc_number'] ?: 'CF' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($v['receiver_name'] ?? 'Consumidor Final', 26) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($v['receiver_tax_condition'] ?? 'CF', 16) }}</td>
                        <td class="num">{{ $fmt($v['net_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['net_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['net_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($v['exempt_amount'] ?? 0) + ($v['untaxed_amount'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($v['tribute_amount'] ?? 0) }}</td>
                        <td class="num"><strong>{{ $fmt($v['total_amount'] ?? 0) }}</strong></td>
                        <td>{{ $v['cae'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="16" style="text-align: center; padding: 18px; color: #64748b;">
                            No se registran comprobantes fiscales autorizados con CAE en el período seleccionado.
                        </td>
                    </tr>
                @endforelse
                @if(count($vouchers) > 0)
                    <tr class="total-row">
                        <td colspan="6">TOTALES CONSOLIDADOS DEL PERÍODO ({{ count($vouchers) }} comprobantes)</td>
                        <td class="num">{{ $fmt($summary['net_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['iva_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['net_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['iva_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['net_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['iva_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($summary['total_exempt'] ?? 0) + ($summary['total_untaxed'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($summary['total_tributes'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['net_fiscal_total'] ?? 0) }}</td>
                        <td></td>
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="footer">
            Subdiario de IVA Ventas generado por Sistema POS · Datos extraídos de comprobantes electrónicos autorizados por ARCA / AFIP (WSFEv1).
        </div>
    </div>
</body>
</html>
