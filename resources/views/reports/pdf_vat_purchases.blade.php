<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Libro IVA Compras</title>
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
        .kpi-value.iva { color: #047857; }
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
        .historical-badge {
            color: #d97706;
            font-style: italic;
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
                    <div class="brand-name">LIBRO IVA COMPRAS — SUBDIARIO FISCAL ARCA</div>
                    <div class="brand-subtitle">
                        {{ $companyName ?: 'Comercio Responsable Inscripto' }}
                        @if(!empty($companyCuit)) · CUIT: {{ $companyCuit }} @endif
                    </div>
                </td>
                <td class="report-meta">
                    <div><strong>Período Fiscal:</strong> {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</div>
                    <div><strong>Generado:</strong> {{ $generatedAt }}</div>
                    <div><strong>Comprobantes Computados:</strong> {{ $summary['vouchers_count'] ?? count($vouchers) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="kpi-section">
        <table class="kpi-table">
            <tr>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Total Compras (Neto de NC)</div>
                    <div class="kpi-value">{{ $fmt($summary['total_amount'] ?? 0) }}</div>
                    <div class="kpi-sub">Facturas: {{ $summary['invoices_count'] ?? 0 }} | NC: {{ $summary['credit_notes_count'] ?? 0 }}</div>
                </td>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Total Neto Gravado (Base Imp.)</div>
                    <div class="kpi-value net">{{ $fmt($summary['total_net'] ?? 0) }}</div>
                    <div class="kpi-sub">21%: {{ $fmt($summary['total_net_21'] ?? 0) }} | 10.5%: {{ $fmt($summary['total_net_105'] ?? 0) }}</div>
                </td>
                <td class="kpi-card" width="34%">
                    <div class="kpi-label">IVA Crédito Fiscal a Declarar</div>
                    <div class="kpi-value iva">{{ $fmt($summary['total_credit_fiscal'] ?? 0) }}</div>
                    <div class="kpi-sub">
                        IVA 21%: <strong>{{ $fmt($summary['total_iva_21'] ?? 0) }}</strong> ·
                        IVA 10.5%: <strong>{{ $fmt($summary['total_iva_105'] ?? 0) }}</strong> ·
                        IVA 27%: <strong>{{ $fmt($summary['total_iva_27'] ?? 0) }}</strong>
                    </div>
                </td>
                <td class="kpi-card" width="22%">
                    <div class="kpi-label">Percepciones / Imp. Int.</div>
                    <div class="kpi-value">{{ $fmt(($summary['total_iva_perception'] ?? 0) + ($summary['total_iibb_perception'] ?? 0) + ($summary['total_internal_taxes'] ?? 0)) }}</div>
                    <div class="kpi-sub">IVA: {{ $fmt($summary['total_iva_perception'] ?? 0) }} | IIBB: {{ $fmt($summary['total_iibb_perception'] ?? 0) }}@if(($summary['total_internal_taxes'] ?? 0) != 0) | Int: {{ $fmt($summary['total_internal_taxes'] ?? 0) }}@endif</div>
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
                    <th>CUIT Proveedor</th>
                    <th>Proveedor / Razón Social</th>
                    <th>Cond. IVA</th>
                    <th class="num">Neto 21%</th>
                    <th class="num">IVA 21%</th>
                    <th class="num">Neto 10.5%</th>
                    <th class="num">IVA 10.5%</th>
                    <th class="num">Neto 27%</th>
                    <th class="num">IVA 27%</th>
                    <th class="num">No Grav./Ex.</th>
                    <th class="num">Percep. IVA</th>
                    <th class="num">IIBB / Imp. Int.</th>
                    <th class="num">Crédito Fiscal</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($vouchers as $v)
                    <tr>
                        <td>{{ !empty($v['issue_date']) ? \Carbon\Carbon::parse($v['issue_date'])->format('d/m/Y') : '-' }}</td>
                        <td class="{{ ($v['kind'] ?? '') === 'credit_note' ? 'nc-badge' : '' }}">
                            {{ $v['voucher_label'] ?? '' }}
                            @if(!empty($v['is_historical']))
                                <span class="historical-badge" title="Dato histórico sin desglose">*</span>
                            @endif
                        </td>
                        <td>{{ $v['formatted_number'] ?? ($v['invoice_number'] ?? 'S/N') }}</td>
                        <td>{{ $v['supplier_cuit'] ?: '-' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($v['supplier_name'] ?? 'Proveedor', 24) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($v['supplier_tax_condition'] ?? 'Resp. Inscripto', 16) }}</td>
                        <td class="num">{{ $fmt($v['net_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['net_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['net_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($v['iva_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($v['non_taxed_amount'] ?? 0) + ($v['exempt_amount'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($v['iva_perception'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($v['iibb_perception'] ?? 0) + ($v['internal_taxes'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($v['credit_fiscal'] ?? 0) }}</td>
                        <td class="num"><strong>{{ $fmt($v['total_amount'] ?? 0) }}</strong></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="17" style="text-align: center; padding: 18px; color: #64748b;">
                            No se registran comprobantes de compras computables para el Libro IVA en el período seleccionado.
                        </td>
                    </tr>
                @endforelse
                @if(count($vouchers) > 0)
                    <tr class="total-row">
                        <td colspan="6">TOTALES CONSOLIDADOS DEL PERÍODO ({{ count($vouchers) }} comprobantes)</td>
                        <td class="num">{{ $fmt($summary['total_net_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_iva_21'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_net_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_iva_105'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_net_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_iva_27'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($summary['total_non_taxed'] ?? 0) + ($summary['total_exempt'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($summary['total_iva_perception'] ?? 0) }}</td>
                        <td class="num">{{ $fmt(($summary['total_iibb_perception'] ?? 0) + ($summary['total_internal_taxes'] ?? 0)) }}</td>
                        <td class="num">{{ $fmt($summary['total_credit_fiscal'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($summary['total_amount'] ?? 0) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="footer">
            Subdiario de IVA Compras generado por Sistema POS · Registro interno fiscal de comprobantes de compras y servicios.
        </div>
    </div>
</body>
</html>
