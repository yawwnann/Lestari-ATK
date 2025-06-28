<?php
// File: app/Http/Requests/StorePesananApiRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePesananApiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Sesuaikan dengan kebutuhan otorisasi Anda.
        // Jika API ini memerlukan otentikasi (misal via Sanctum) dan user yang login boleh membuat pesanan,
        // maka 'true' adalah benar jika otorisasi ditangani di middleware route.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nama_pelanggan' => ['required', 'string', 'max:255'],
            'nomor_whatsapp' => ['nullable', 'string', 'max:20'],
            'alamat_pengiriman' => ['nullable', 'string'],
            'catatan' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], // Wajib ada item, minimal 1
            // Diubah: 'ikan_id' menjadi 'pupuk_id' dan tabel 'ikan' menjadi 'pupuk'
            'items.*.pupuk_id' => ['required', 'integer', 'exists:pupuk,id'], // Setiap item harus punya pupuk_id yg valid di tabel pupuk
            'items.*.jumlah' => ['required', 'integer', 'min:1'], // Setiap item harus punya jumlah minimal 1
            // Jika Anda juga mengirim 'harga_saat_pesanan' dari frontend dan ingin memvalidasinya:
            // 'items.*.harga_saat_pesanan' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Custom message for validation errors.
     * @return array
     */
    public function messages(): array
    {
        return [
            'nama_pelanggan.required' => 'Nama pelanggan wajib diisi.',
            'items.required' => 'Minimal ada satu item pupuk yang dipesan.', // Pesan disesuaikan
            'items.min' => 'Minimal ada satu item pupuk yang dipesan.',      // Pesan disesuaikan
            'items.*.pupuk_id.required' => 'ID Pupuk wajib dipilih untuk setiap item.', // Pesan disesuaikan
            'items.*.pupuk_id.exists' => 'ID Pupuk yang dipilih tidak valid.',        // Pesan disesuaikan
            'items.*.jumlah.required' => 'Jumlah wajib diisi untuk setiap item.',
            'items.*.jumlah.min' => 'Jumlah minimal adalah 1 untuk setiap item.',
        ];
    }
}