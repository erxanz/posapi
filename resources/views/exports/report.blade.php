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

        .kpi-container {
            margin-bottom: 30px;
            clear: both;
            overflow: hidden;
        }

        .kpi-box {
            width: 45%;
            float: left;
            background: #fcfcfc;
            border: 1px solid #eef2f6;
            padding: 15px;
            border-radius: 8px;
            margin-right: 2%;
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

        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1a2332;
            clear: both;
            padding-top: 10px;
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
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #eef2f6;
        }

        td {
            padding: 8px 10px;
            font-size: 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .tr-even {
            background-color: #fafafa;
        }

        .text-right {
            text-align: right;
        }

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
        <span class="brand">MINI POS</span>
        <div class="report-info">
            <strong>LAPORAN {{ strtoupper($reportType) }}</strong><br>
            Periode: {{ $startDate->format('d/m/Y') }} - {{ $endDate->format('d/m/Y') }}<br>
            Outlet: {{ $outletName }}
        </div>
    </div>

    <div class="kpi-container">
        <div class="kpi-box">
            <div class="kpi-label">Total Pendapatan Bersih</div>
            <div class="kpi-value">Rp {{ number_format($summary['revenue'], 0, ',', '.') }}</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Total Transaksi Selesai</div>
            <div class="kpi-value">{{ number_format($summary['transactions'], 0, ',', '.') }} Trx</div>
        </div>
    </div>

    <div class="section-title">Detail Data Lampiran</div>

    <table>
        {{-- KONDISI 1: JIKA TAB SUMMARY / SALES --}}
        @if($reportType === 'summary' || $reportType === 'sales')
        <thead>
            <tr>
                <th>Tanggal</th>
                <th class="text-right">Transaksi</th>
                <th class="text-right">Gross (Kotor)</th>
                <th class="text-right">Diskon</th>
                <th class="text-right">Pajak</th>
                <th class="text-right">Net (Bersih)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr class="{{ $loop->even ? 'tr-even' : '' }}">
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                <td class="text-right">{{ $row['transactions'] }}</td>
                <td class="text-right">Rp {{ number_format($row['gross'], 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($row['discount'], 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($row['tax'], 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($row['net'], 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>

        {{-- KONDISI 2: JIKA TAB KINERJA PRODUK --}}
        @elseif($reportType === 'products')
        <thead>
            <tr>
                <th>Nama Produk</th>
                <th>Kategori</th>
                <th class="text-right">Terjual</th>
                <th class="text-right">Harga Rata-rata</th>
                <th class="text-right">Total Pendapatan (Gross)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr class="{{ $loop->even ? 'tr-even' : '' }}">
                <td>{{ $row->name }}</td>
                <td>{{ $row->category ?? 'Lainnya' }}</td>
                <td class="text-right">{{ $row->sold }}</td>
                <td class="text-right">Rp {{ number_format(round($row->avg_price), 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($row->revenue, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>

        {{-- KONDISI 3: JIKA TAB KINERJA SHIFT --}}
        @elseif($reportType === 'shifts')
        <thead>
            <tr>
                <th>Kasir</th>
                <th>Jam Mulai</th>
                <th>Jam Selesai</th>
                <th class="text-right">Sistem</th>
                <th class="text-right">Uang Laci</th>
                <th class="text-right">Selisih</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr class="{{ $loop->even ? 'tr-even' : '' }}">
                <td>{{ $row->cashier ?? 'Tidak Diketahui' }}</td>
                <td>{{ \Carbon\Carbon::parse($row->started_at)->format('d/m y H:i') }}</td>
                <td>{{ \Carbon\Carbon::parse($row->ended_at)->format('d/m y H:i') }}</td>
                <td class="text-right">Rp {{ number_format($row->closing_balance_system, 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($row->closing_balance_actual, 0, ',', '.') }}</td>
                <td class="text-right" style="{{ $row->difference < 0 ? 'color: red;' : 'color: green;' }}">
                    Rp {{ number_format($row->difference, 0, ',', '.') }}
                </td>
            </tr>
            @endforeach
        </tbody>

        {{-- KONDISI 4: JIKA TAB KINERJA KASIR --}}
        @elseif($reportType === 'staff')
        <thead>
            <tr>
                <th>Nama Kasir</th>
                <th>Outlet</th>
                <th class="text-right">Trx Ditangani</th>
                <th class="text-right">Pendapatan Diterima</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr class="{{ $loop->even ? 'tr-even' : '' }}">
                <td>{{ $row->name ?? 'Terhapus' }}</td>
                <td>{{ $row->outlet_name }}</td>
                <td class="text-right">{{ $row->transactions }}</td>
                <td class="text-right">Rp {{ number_format($row->revenue, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
        @endif
    </table>

    <div class="footer">
        Dicetak secara otomatis pada {{ now()->format('d/m/Y H:i') }}
    </div>
</body>

</html>
