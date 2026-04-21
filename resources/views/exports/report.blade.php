<!DOCTYPE html>
<html>

<head>
    <title>Laporan {{ ucfirst($reportType) }}</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #dddddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="header">
        <h2>LAPORAN ENTERPRISE - {{ strtoupper($reportType) }}</h2>
        <p>Periode: {{ $startDate->format('d/m/Y') }} s/d {{ $endDate->format('d/m/Y') }} <br> Outlet: {{ $outletName }}
        </p>
    </div>

    <table>
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
            <tr>
                <td>{{ $row['date'] }}</td>
                <td class="text-right">{{ $row['transactions'] }}</td>
                <td class="text-right">{{ $row['gross'] }}</td>
                <td class="text-right">{{ $row['discount'] }}</td>
                <td class="text-right">{{ $row['tax'] }}</td>
                <td class="text-right">{{ $row['net'] }}</td>
            </tr>
            @endforeach
        </tbody>
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
            <tr>
                <td>{{ $row->name }}</td>
                <td>{{ $row->category ?? 'Lainnya' }}</td>
                <td class="text-right">{{ $row->sold }}</td>
                <td class="text-right">{{ round($row->avg_price) }}</td>
                <td class="text-right">{{ $row->revenue }}</td>
            </tr>
            @endforeach
        </tbody>
        @elseif($reportType === 'shifts')
        <thead>
            <tr>
                <th>Kasir</th>
                <th>Mulai</th>
                <th>Selesai</th>
                <th class="text-right">Modal Awal</th>
                <th class="text-right">Catatan Sistem</th>
                <th class="text-right">Uang Fisik (Laci)</th>
                <th class="text-right">Selisih (Variance)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr>
                <td>{{ $row->cashier ?? 'Tidak Diketahui' }}</td>
                <td>{{ $row->started_at }}</td>
                <td>{{ $row->ended_at }}</td>
                <td class="text-right">{{ $row->opening_balance }}</td>
                <td class="text-right">{{ $row->closing_balance_system }}</td>
                <td class="text-right">{{ $row->closing_balance_actual }}</td>
                <td class="text-right">{{ $row->difference }}</td>
            </tr>
            @endforeach
        </tbody>
        @elseif($reportType === 'staff')
        <thead>
            <tr>
                <th>Nama Kasir</th>
                <th>Outlet</th>
                <th class="text-right">Trx Ditangani</th>
                <th class="text-right">Uang Diterima (Bersih)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr>
                <td>{{ $row->name ?? 'Terhapus' }}</td>
                <td>{{ $row->outlet_name }}</td>
                <td class="text-right">{{ $row->transactions }}</td>
                <td class="text-right">{{ $row->revenue }}</td>
            </tr>
            @endforeach
        </tbody>
        @endif
    </table>
</body>

</html>
