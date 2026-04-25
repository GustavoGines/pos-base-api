<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Rentabilidad</title>
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

        /* Fila de categoría */
        .row-category {
            background: #f1f5f9;
        }
        .row-category td {
            padding: 7px 10px;
            font-weight: 700;
            font-size: 11px;
            color: #1e3a5f;
            border-bottom: 1px solid #e2e8f0;
        }
        .row-category td.num { text-align: right; }
        .cat-icon {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #1e3a5f;
            border-radius: 2px;
            margin-right: 6px;
            vertical-align: middle;
        }

        /* Fila de producto */
        .row-product {
            background: #ffffff;
        }
        .row-product:hover { background: #fafafa; }
        .row-product td {
            padding: 5px 10px 5px 28px;
            font-size: 10px;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
        }
        .row-product td.num { text-align: right; }
        .product-arrow {
            color: #94a3b8;
            margin-right: 4px;
        }

        /* Colores de ganancia/pérdida */
        .profit-pos { color: #16a34a; font-weight: 600; }
        .profit-neg { color: #dc2626; font-weight: 600; }

        /* Fila de subtotal por categoría */
        .row-subtotal td {
            padding: 4px 10px;
            font-size: 9px;
            color: #94a3b8;
            border-bottom: 2px solid #e2e8f0;
            text-align: right;
            font-style: italic;
        }
        .row-subtotal td:first-child { text-align: left; padding-left: 28px; }

        /* Fila de totales finales */
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

        /* ─── Utilidades ─── */
        .text-right { text-align: right; }
        .page-break  { page-break-after: always; }
    </style>
</head>
<body>

{{-- ═══════════════════════ ENCABEZADO ═══════════════════════ --}}
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
        <div class="report-title">{{ $reportTitle ?? 'Reporte de Rentabilidad' }}</div>
        <div class="period-badge">
            {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}
            &nbsp;-&nbsp;
            {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}
        </div>
    </div>
</div>

{{-- ═══════════════════════ KPI RESUMEN ═══════════════════════ --}}
<div class="kpi-section">
    <div class="kpi-title">Resumen Ejecutivo del Período</div>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Facturación Total</div>
            <div class="kpi-value">${{ number_format($totalRevenue, 2, ',', '.') }}</div>
            <div class="kpi-sub">Ingresos brutos del período</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Costo de Ventas</div>
            <div class="kpi-value cost">${{ number_format($totalCost, 2, ',', '.') }}</div>
            <div class="kpi-sub">Sobre ítems con costo cargado</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ganancia Neta</div>
            <div class="kpi-value profit">${{ number_format($totalProfit, 2, ',', '.') }}</div>
            <div class="kpi-sub">Ingresos menos costos</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Margen Promedio</div>
            <div class="kpi-value margin">{{ number_format($avgMargin, 1, ',', '.') }}%</div>
            <div class="kpi-sub">Sobre ventas con costo registrado</div>
        </div>
    </div>
</div>

{{-- ═══════════════════════ RESUMEN POR PLAN / LISTA ═══════════════════════ --}}
@if(isset($salesByPlan) && count($salesByPlan) > 0)
<div class="content" style="padding-bottom: 0;">
    <div class="section-title">Desglose por Lista de Precios (Plan)</div>
    <table>
        <thead>
            <tr>
                <th style="width:40%">Lista de Precios Aplicada</th>
                <th class="num" style="width:20%">Tickets Emitidos</th>
                <th class="num" style="width:40%">Facturación Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($salesByPlan as $plan)
                <tr class="row-product">
                    <td style="font-weight: 600; text-transform: uppercase;">
                        <span class="cat-icon" style="background: #7c3aed;"></span>
                        {{ $plan->plan_name === 'base' ? 'Minorista (Base)' : $plan->plan_name }}
                    </td>
                    <td class="num">{{ number_format($plan->total_tickets, 0, ',', '.') }}</td>
                    <td class="num" style="font-weight: 600; color: #1e3a5f;">
                        ${{ number_format($plan->total_revenue, 2, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ═══════════════════════ TABLA DETALLADA ═══════════════════ --}}
<div class="content">
    <div class="section-title">Detalle por Categoría y Producto</div>

    <table>
        <thead>
            <tr>
                <th style="width:38%">Categoría / Producto</th>
                <th class="num" style="width:10%">Uds. Vend.</th>
                <th class="num" style="width:17%">Facturación</th>
                <th class="num" style="width:17%">Ganancia</th>
                <th class="num" style="width:10%">Margen %</th>
            </tr>
        </thead>
        <tbody>
            @php
                $grandRevenue = 0;
                $grandProfit  = 0;
                $grandQty     = 0;
            @endphp

            @foreach($data as $category)
                @php
                    $catRev    = $category['total_revenue'];
                    $catProfit = $category['total_profit'];
                    $catRWC    = $category['revenue_with_cost'];
                    $catMargin = $catRWC > 0 ? ($catProfit / $catRWC) * 100 : 0;
                    $catQty    = $category['items_sold'];
                    $grandRevenue += $catRev;
                    $grandProfit  += $catProfit;
                    $grandQty     += $catQty;
                @endphp
                {{-- Fila de Categoría --}}
                <tr class="row-category">
                    <td>
                        <span class="cat-icon"></span>
                        {{ $category['category_name'] }}
                    </td>
                    <td class="num">{{ number_format($catQty, 0, ',', '.') }}</td>
                    <td class="num">${{ number_format($catRev, 2, ',', '.') }}</td>
                    <td class="num {{ $catProfit >= 0 ? 'profit-pos' : 'profit-neg' }}">
                        ${{ number_format($catProfit, 2, ',', '.') }}
                    </td>
                    <td class="num">{{ number_format($catMargin, 1, ',', '.') }}%</td>
                </tr>

                {{-- Filas de Productos --}}
                @foreach($category['products'] as $prod)
                    @php
                        $pRev    = $prod['total_revenue'];
                        $pProf   = $prod['total_profit'];
                        $pRWC    = $prod['revenue_with_cost'];
                        $pMargin = $pRWC > 0 ? ($pProf / $pRWC) * 100 : 0;
                    @endphp
                    <tr class="row-product">
                        <td>
                            <span class="product-arrow">></span>
                            {{ $prod['product_name'] }}
                        </td>
                        <td class="num">{{ number_format($prod['items_sold'], 0, ',', '.') }}</td>
                        <td class="num">${{ number_format($pRev, 2, ',', '.') }}</td>
                        <td class="num {{ $pProf >= 0 ? 'profit-pos' : 'profit-neg' }}">
                            ${{ number_format($pProf, 2, ',', '.') }}
                        </td>
                        <td class="num">{{ number_format($pMargin, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            @endforeach

            {{-- Fila de Totales Finales --}}
            @php
                $grandWithCost = $data->sum('revenue_with_cost');
                $grandMargin   = $grandWithCost > 0 ? ($grandProfit / $grandWithCost) * 100 : 0;
            @endphp
            <tr class="row-total">
                <td>TOTAL GENERAL</td>
                <td class="num">{{ number_format($grandQty, 0, ',', '.') }}</td>
                <td class="num">${{ number_format($grandRevenue, 2, ',', '.') }}</td>
                <td class="num">${{ number_format($grandProfit, 2, ',', '.') }}</td>
                <td class="num">{{ number_format($grandMargin, 1, ',', '.') }}%</td>
            </tr>
        </tbody>
    </table>

    {{-- Nota al pie interna --}}
    <div style="margin-top:10px; font-size:9px; color:#94a3b8; line-height:1.6;">
        * El margen se calcula únicamente sobre los ítems que cuentan con costo registrado en el catálogo.
        Los artículos sin costo asignado contribuyen a la facturación pero no al cálculo de margen, para evitar distorsiones estadísticas.
    </div>
</div>

{{-- ═══════════════════════ PIE DE PÁGINA ════════════════════ --}}
<div class="footer" style="padding: 0 24px 14px;">
    <span>Sistema POS · Módulo Enterprise · Confidencial</span>
    <span>Generado automáticamente el {{ $generatedAt }}</span>
</div>

</body>
</html>
