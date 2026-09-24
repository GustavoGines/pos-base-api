<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Gastos</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1a1a2e;
            background: #ffffff;
        }

        /* Encabezado Corporativo */
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
            letter-spacing: -0.5px;
            margin-bottom: 2px;
        }
        .report-title {
            font-size: 11px;
            color: #8da4c4;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        .header-info {
            text-align: right;
            line-height: 1.4;
        }
        .period-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 10px;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .generated-date {
            font-size: 10px;
            color: #8da4c4;
        }

        /* Contenido */
        .content {
            padding: 24px;
        }

        /* Panel de KPIs (Total) */
        .kpi-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 16px;
        }
        .kpi-box {
            flex: 1;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 16px;
            border-radius: 6px;
        }
        .kpi-box.total {
            background: #fff5f5;
            border: 1px solid #ffe3e3;
        }
        .kpi-title {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: bold;
            color: #212529;
        }
        .kpi-value.expense {
            color: #dc3545;
        }

        /* Tabla de Análisis */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        thead th {
            background: #f1f3f5;
            color: #495057;
            font-size: 10px;
            text-transform: uppercase;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            letter-spacing: 0.5px;
        }
        tbody td {
            padding: 12px;
            border-bottom: 1px solid #f1f3f5;
            color: #343a40;
            font-size: 11px;
        }
        tbody tr:nth-child(even) td {
            background-color: #fafbfc;
        }
        
        /* Utilidades */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .text-danger { color: #e03131; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #868e96;
            background: #f8f9fa;
            border-radius: 6px;
            margin-top: 20px;
        }

        /* Pie de página */
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 9px;
            color: #adb5bd;
            border-top: 1px solid #f1f3f5;
            padding-top: 16px;
        }

    </style>
</head>
<body>

    <div class="header">
        <div class="header-top">
            <div>
                <div class="brand-name">Sistema POS</div>
                <div class="report-title">Análisis de Gastos</div>
            </div>
            <div class="header-info">
                <div class="period-badge">
                    {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} 
                    &nbsp;&rarr;&nbsp; 
                    {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}
                </div>
                <div class="generated-date">Generado: {{ now()->format('d/m/Y H:i') }}</div>
            </div>
        </div>
    </div>

    <div class="content">

        <!-- KPIs Generales -->
        <div class="kpi-container">
            <div class="kpi-box total">
                <div class="kpi-title">Gastos Totales del Período</div>
                <div class="kpi-value expense">
                    ${{ number_format($totalExpenses, 2, ',', '.') }}
                </div>
            </div>
            <div class="kpi-box">
                <div class="kpi-title">Categorías Involucradas</div>
                <div class="kpi-value">
                    {{ count($expenses) }}
                </div>
            </div>
            <div class="kpi-box">
                <div class="kpi-title">Cantidad de Movimientos</div>
                <div class="kpi-value">
                    {{ collect($expenses)->sum('transactions') }}
                </div>
            </div>
        </div>

        @if(count($expenses) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Categoría</th>
                        <th class="text-center">Transacciones</th>
                        <th class="text-right">Monto</th>
                        <th class="text-right">Proporción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expenses as $item)
                        @php
                            $total_amount = $item->total_amount ?? 0;
                            $transactions = $item->transactions ?? 0;
                            $percentage = $totalExpenses > 0 ? ($total_amount / $totalExpenses) * 100 : 0;
                        @endphp
                        <tr>
                            <td class="font-bold">{{ $item->category_name }}</td>
                            <td class="text-center">{{ $transactions }}</td>
                            <td class="text-right font-bold text-danger">
                                ${{ number_format($total_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-right">
                                {{ number_format($percentage, 1, ',', '.') }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty-state">
                <div style="font-size: 14px; font-weight: bold; margin-bottom: 6px;">No hay gastos registrados</div>
                <div>No se encontraron egresos en el período seleccionado.</div>
            </div>
        @endif

        <div class="footer">
            Este documento es un reporte generado automáticamente. 
            <br>
            Página 1 de 1
        </div>
    </div>

</body>
</html>
