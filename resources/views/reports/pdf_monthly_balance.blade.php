<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Balance Mensual</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1a1a2e;
            background: #ffffff;
        }

        /* ─── Encabezado Corporativo ─── */
        .header {
            background: #1e3a5f;
            color: #ffffff;
            padding: 20px 24px 18px;
            border-radius: 0 0 4px 4px;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .brand-name {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .brand-subtitle {
            font-size: 11px;
            color: #a8c4e0;
            margin-top: 2px;
        }
        .report-meta {
            text-align: right;
            font-size: 10px;
            color: #a8c4e0;
            line-height: 1.7;
        }
        .report-title-block {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .report-title {
            font-size: 16px;
            font-weight: 600;
            color: #ffffff;
        }
        .period-badge {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: #ffffff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        /* ─── KPIs ─── */
        .kpi-section {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .kpi-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .kpi-grid {
            display: flex;
            gap: 12px;
        }
        .kpi-card {
            flex: 1;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .kpi-label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a5f;
            line-height: 1;
        }
        .kpi-value.profit { color: #16a34a; }
        .kpi-value.cost   { color: #dc2626; }
        .kpi-value.margin { color: #7c3aed; }
        .kpi-sub {
            font-size: 9px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* ─── Cuerpo del Reporte ─── */
        .content {
            padding: 18px 24px 24px;
        }
        .section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            margin-bottom: 10px;
        }

        /* ─── Tabla ─── */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table thead tr {
            background: #1e3a5f;
            color: #ffffff;
        }
        table thead th {
            padding: 8px 10px;
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table thead th.num { text-align: right; }

        .row-product {
            background: #ffffff;
        }
        .row-product:nth-child(even) { background: #f8fafc; }
        .row-product td {
            padding: 9px 10px;
            font-size: 11px;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
        }
        .row-product td.num { text-align: right; }

        .profit-pos { color: #16a34a; font-weight: 600; }
        .profit-neg { color: #dc2626; font-weight: 600; }

        .row-total {
            background: #1e3a5f;
        }
        .row-total td {
            padding: 9px 10px;
            font-size: 11px;
            font-weight: 700;
            color: #ffffff;
        }
        .row-total td.num { text-align: right; }

        /* ─── Pie de página ─── */
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 8.5px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-top">
        <div>
            <div class="brand-name">Sistema POS</div>
            <div class="brand-subtitle">Módulo de Rentabilidad y Auditoría</div>
        </div>
        <div class="report-meta">
            <div>Generado el {{ $generatedAt }}</div>
            <div>Confidencial · Uso Interno</div>
        </div>
    </div>
    <div class="report-title-block">
        <div class="report-title">Reporte de Balance Mensual</div>
        <div class="period-badge">
            {{ \Carbon\Carbon::parse($startMonth . '-01')->format('m/Y') }}
            &nbsp;-&nbsp;
            {{ \Carbon\Carbon::parse($endMonth . '-01')->format('m/Y') }}
        </div>
    </div>
</div>

<div class="kpi-section">
    <div class="kpi-title">Resumen del Período</div>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Facturación Total</div>
            <div class="kpi-value">${{ number_format($data['totals']['total_revenue'], 2, ',', '.') }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Costo de Ventas</div>
            <div class="kpi-value cost">${{ number_format($data['totals']['total_cost'], 2, ',', '.') }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ganancia Neta</div>
            <div class="kpi-value profit">${{ number_format($data['totals']['total_profit'], 2, ',', '.') }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Margen Promedio</div>
            <div class="kpi-value margin">{{ number_format($data['totals']['avg_margin_pct'], 1, ',', '.') }}%</div>
        </div>
    </div>
</div>

<div class="content">
    <div class="section-title">Evolución Mes a Mes</div>

    <table>
        <thead>
            <tr>
                <th style="width:20%">Mes / Período</th>
                <th class="num" style="width:15%">Tickets</th>
                <th class="num" style="width:20%">Facturación</th>
                <th class="num" style="width:15%">Costo</th>
                <th class="num" style="width:15%">Ganancia</th>
                <th class="num" style="width:15%">Margen %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['months'] as $month)
                <tr class="row-product">
                    <td style="font-weight: 600; text-transform: capitalize;">{{ $month['label'] }}</td>
                    <td class="num">{{ number_format($month['transactions'], 0, ',', '.') }}</td>
                    <td class="num">${{ number_format($month['total_revenue'], 2, ',', '.') }}</td>
                    <td class="num">${{ number_format($month['total_cost'], 2, ',', '.') }}</td>
                    <td class="num {{ $month['total_profit'] >= 0 ? 'profit-pos' : 'profit-neg' }}">
                        ${{ number_format($month['total_profit'], 2, ',', '.') }}
                    </td>
                    <td class="num">{{ number_format($month['margin_pct'], 1, ',', '.') }}%</td>
                </tr>
            @endforeach

            <tr class="row-total">
                <td>TOTAL GENERAL</td>
                <td class="num">{{ number_format($data['months']->sum('transactions'), 0, ',', '.') }}</td>
                <td class="num">${{ number_format($data['totals']['total_revenue'], 2, ',', '.') }}</td>
                <td class="num">${{ number_format($data['totals']['total_cost'], 2, ',', '.') }}</td>
                <td class="num">${{ number_format($data['totals']['total_profit'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($data['totals']['avg_margin_pct'], 1, ',', '.') }}%</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="footer" style="padding: 0 24px 14px;">
    <span>Sistema POS · Módulo Enterprise · Confidencial</span>
    <span>Generado automáticamente el {{ $generatedAt }}</span>
</div>

</body>
</html>
