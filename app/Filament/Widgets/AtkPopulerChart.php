<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class AtkPopulerChart extends ChartWidget
{
    protected static ?string $heading = 'Top 5 ATK Paling Laris (Status Selesai)';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $data = DB::table('item_pesanan')
            ->join('pesanan', 'item_pesanan.pesanan_id', '=', 'pesanan.id')
            ->join('atk', 'item_pesanan.atk_id', '=', 'atk.id')
            ->select('atk.nama_atk', DB::raw('SUM(item_pesanan.jumlah) as total_jumlah'))
            ->where('pesanan.status', '=', 'selesai')
            ->groupBy('atk.id', 'atk.nama_atk')
            ->orderByDesc('total_jumlah')
            ->limit(5)
            ->get();

        $labels = $data->pluck('nama_atk')->toArray();
        $values = $data->pluck('total_jumlah')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Jumlah Terjual',
                    'data' => $values,
                    'backgroundColor' => [
                        'rgba(75, 192, 192, 0.6)', // Hijau
                        'rgba(153, 102, 255, 0.6)',// Ungu
                        'rgba(255, 159, 64, 0.6)', // Oranye
                        'rgba(54, 162, 235, 0.6)', // Biru
                        'rgba(255, 99, 132, 0.6)',  // Merah
                    ],
                    'borderColor' => [
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                    ],
                    'borderWidth' => 1
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}