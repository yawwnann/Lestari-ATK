<?php

namespace App\Observers;

use App\Models\Pesanan;
use Illuminate\Support\Facades\Log;

class PesananObserver
{
    public function created(Pesanan $pesanan)
    {
        Log::info("--- PesananObserver@created START for Pesanan ID: {$pesanan->id} ---");

        // ✅ BENAR - Menggunakan relasi items
        if ($pesanan->items->isEmpty()) {
            Log::warning("Tidak ada item relasi ditemukan (items) untuk Pesanan ID: {$pesanan->id} saat observer 'created' dijalankan.");
        } else {
            Log::info("Ditemukan " . $pesanan->items->count() . " items untuk Pesanan ID: {$pesanan->id}");

            // Contoh: Update stok pupuk
            foreach ($pesanan->items as $pupuk) {
                $jumlahPesan = $pupuk->pivot->jumlah;
                $pupuk->decrement('stok', $jumlahPesan);
                Log::info("Stok pupuk '{$pupuk->nama_pupuk}' dikurangi sebanyak {$jumlahPesan}");
            }
        }

        Log::info("--- PesananObserver@created END for Pesanan ID: {$pesanan->id} ---");
    }
}