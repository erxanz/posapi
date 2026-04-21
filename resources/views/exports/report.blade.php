<!DOCTYPE html>
<html>

<head>
    <style>
        @page {
            margin: 1cm;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            color: #333;
            line-height: 1.5;
            margin: 0;
        }

        /* Header Section */
        .header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .brand {
            font-size: 24px;
            font-weight: bold;
            color: #1a2332;
        }

        .report-info {
            float: right;
            text-align: right;
            font-size: 11px;
            color: #5a7a9a;
        }

        /* KPI Section (Grid-like) */
        .kpi-container {
            margin-bottom: 30px;
            clear: both;
        }

        .kpi-box {
            width: 22%;
            float: left;
            margin-right: 2%;
            background: #fcfcfc;
            border: 1px solid #eef2f6;
            padding: 15px;
            border-radius: 8px;
        }

        .kpi-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #5a7a9a;
            font-weight: bold;
        }

        .kpi-value {
            font-size: 16px;
            font-weight: bold;
            color: #2e7dd6;
            margin-top: 5px;
        }

        /* Table Section */
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1a2332;
            clear: both;
            padding-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            color: #5a7a9a;
            font-size: 11px;
            text-transform: uppercase;
            padding: 12px 10px;
            text-align: left;
            border-bottom: 2px solid #eef2f6;
        }

        td {
            padding: 10px;
            font-size: 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .tr-even {
            background-color: #fafafa;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #8aafcc;
            border-top: 1px solid #f0f0f0;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <span class="brand">LUNE POS</span>
        <div class="report-info">
            <strong>LAPORAN {{ strtoupper($reportType) }}</strong><br>
            Periode: {{ $startDate->format('d M Y') }} - {{ $endDate->format('d M Y') }}<br>
            Outlet: {{ $outletName }}
        </div>
    </div>

    <div class="kpi-container">
        <div class="kpi-box">
            <div class="kpi-label">Total Netto</div>
            <div class="kpi-value">Rp {{ number_format($summary['revenue']) }}</div>
        </div>
    </div>

    <div class="section-title">Detail Transaksi Terlampir</div>
    <table>
        <thead>
            <tr>
                <th>Deskripsi</th>
                <th style="text-align: right;">Nilai</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $key => $row)
            <tr class="{{ $loop->even ? 'tr-even' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td style="text-align: right;">{{ $row['value'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak secara otomatis pada {{ now()->format('d/m/Y H:i') }} - Halaman 1 dari 1
    </div>
</body>

</html>
