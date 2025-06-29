<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PesananResource\Pages;
use App\Models\Pesanan;
use App\Models\Atk;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Grid;
use Illuminate\Support\HtmlString;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Forms\Components\Actions\Action as FormComponentAction;
use Illuminate\Database\Eloquent\Builder;

// Helper function untuk format Rupiah
if (!function_exists('App\Filament\Resources\formatFilamentRupiah')) {
    function formatFilamentRupiah($number)
    {
        if ($number === null || is_nan((float) $number)) {
            return 'Rp 0';
        }
        return 'Rp ' . number_format((float) $number, 0, ',', '.');
    }
}

class PesananResource extends Resource
{
    protected static ?string $model = Pesanan::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $modelLabel = 'Pesanan';
    protected static ?string $pluralModelLabel = 'Manajemen Pesanan';
    protected static ?string $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'id';

    // Opsi status
    public static function getStatusPesananOptions(): array
    {
        return [
            'baru' => 'Baru',
            'menunggu_konfirmasi_pembayaran' => 'Menunggu Konfirmasi Pembayaran',
            'lunas' => 'Lunas (Pembayaran Dikonfirmasi)',
            'diproses' => 'Diproses',
            'dikirim' => 'Dikirim',
            'selesai' => 'Selesai',
            'dibatalkan' => 'Dibatalkan',
        ];
    }
    public static function getStatusPembayaranOptions(): array
    {
        return [
            'pending' => 'Pending',
            'menunggu_pembayaran' => 'Menunggu Pembayaran',
            'lunas' => 'Lunas',
            'gagal' => 'Gagal',
            'expired' => 'Kadaluarsa',
            'dibatalkan' => 'Dibatalkan',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Section::make('Informasi Dasar Pesanan')
                        ->columns(2)->columnSpan(2)
                        ->schema([
                            Forms\Components\DatePicker::make('tanggal_pesanan')->label('Tanggal Pesan')->default(now())
                                ->disabled(fn(string $operation): bool => $operation === 'view'),
                            Forms\Components\TextInput::make('nama_pelanggan')->required()->maxLength(255)
                                ->disabled(fn(string $operation): bool => $operation === 'view'),
                            Forms\Components\TextInput::make('nomor_whatsapp')->label('Nomor WhatsApp')->tel()->maxLength(20)->required()
                                ->disabled(fn(string $operation): bool => $operation === 'view'),
                            Forms\Components\Select::make('user_id')->label('User Terdaftar (Opsional)')->relationship('user', 'name')
                                ->searchable()->preload()->placeholder('Pilih User Akun')
                                ->disabled(fn(string $operation): bool => $operation === 'view'),
                            Forms\Components\Textarea::make('alamat_pengiriman')->label('Alamat Pengiriman')->rows(3)
                                ->required()->columnSpanFull()->disabledOn('view'),
                            Forms\Components\Textarea::make('catatan')->label('Catatan Pelanggan')->rows(3)->nullable()->columnSpanFull()
                                ->disabledOn('view'),
                        ]),

                    Section::make('Status & Pembayaran')
                        ->columnSpan(1)
                        ->schema([
                            Forms\Components\TextInput::make('total_harga')->label('Total Keseluruhan')->numeric()->prefix('Rp')->readOnly(),

                            Forms\Components\Select::make('metode_pembayaran')
                                ->label('Metode Pembayaran')
                                ->options([
                                    'Transfer Bank' => 'Transfer Bank',
                                    'COD' => 'Cash on Delivery',
                                    'E-Wallet' => 'E-Wallet',
                                ])
                                ->required()
                                ->native(false)
                                ->disabled(fn(string $operation): bool => $operation === 'view'),

                            Forms\Components\Select::make('status')->label('Status Pesanan')
                                ->options(self::getStatusPesananOptions())->required()->default('baru')->native(false)
                                ->disabled(fn(string $operation, ?Pesanan $record): bool => $operation === 'view' || ($operation === 'create') || (isset($record) && in_array($record->status, ['selesai', 'dibatalkan']))),
                            Forms\Components\Select::make('status_pembayaran')->label('Status Pembayaran')
                                ->options(self::getStatusPembayaranOptions())->placeholder('Pilih Status Pembayaran')->native(false)
                                ->disabled(fn(string $operation, ?Pesanan $record): bool => $operation === 'view' || ($operation === 'create' && !$record?->status_pembayaran) || (isset($record) && in_array($record->status_pembayaran, ['lunas', 'gagal', 'expired', 'dibatalkan']))),
                            Forms\Components\Textarea::make('catatan_admin')->label('Catatan Internal Admin')->rows(4)->nullable()->columnSpanFull()
                                ->disabled(fn(string $operation): bool => $operation === 'view'),
                            Forms\Components\Placeholder::make('bukti_pembayaran')
                                ->label('Bukti Pembayaran')
                                ->content(function (?Pesanan $record) {
                                    if ($record && $record->payment_proof_path) {
                                        $url = $record->payment_proof_path;
                                        return new HtmlString("<a href='{$url}' target='_blank'><img src='{$url}' alt='Bukti Pembayaran' style='max-width:200px;max-height:200px;border-radius:8px;'></a>");
                                    }
                                    return new HtmlString('<span class=\"text-gray-500\">Belum ada bukti pembayaran</span>');
                                })
                                ->columnSpanFull()
                                ->visible(fn(string $operation) => $operation === 'view' || $operation === 'edit'),
                        ]),
                ]),

                Section::make('Item ATK Dipesan')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label(fn(string $operation) => $operation === 'view' ? '' : 'Item ATK')
                            // HAPUS BARIS INI: ->relationship()
                            // Karena Anda menangani attach/sync secara manual di CreatePesanan.php dan EditPesanan.php,
                            // menghapus ini akan mencegah Filament mencoba menyimpan model Atk yang tidak lengkap.
                            ->schema([
                                Forms\Components\Select::make('atk_id')->label('Pilih ATK')
                                    ->options(function (Get $get) {
                                        $currentItems = $get('../../items') ?? [];
                                        $existingAtkIdsInRepeater = collect($currentItems)->pluck('atk_id')->filter()->all();
                                        return Atk::query()
                                            ->where('stok', '>', 0)
                                            ->orWhereIn('id', $existingAtkIdsInRepeater)
                                            ->orderBy('nama_atk')
                                            ->pluck('nama_atk', 'id');
                                    })
                                    ->required()->reactive()->searchable()->preload()
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        $atk = Atk::find($state);
                                        $set('harga_saat_pesanan', $atk?->harga ?? 0);
                                        // Set nilai untuk placeholder kategori
                                        $set('kategori_atk_display', $atk->kategoriAtk->nama_kategori ?? 'Tidak Berkategori');
                                    })
                                    ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(['md' => 4]), // Kolom untuk pilih atk

                                Forms\Components\TextInput::make('jumlah')->label('Jumlah')->numeric()->required()->minValue(1)->default(1)->reactive()
                                    ->columnSpan(['md' => 2]), // Kolom untuk jumlah

                                Forms\Components\TextInput::make('harga_saat_pesanan')->label('Harga Satuan')->numeric()->prefix('Rp')->required()
                                    ->disabled()->dehydrated()
                                    ->columnSpan(['md' => 2]), // Kolom untuk harga satuan

                                // Tambahkan placeholder untuk menampilkan kategori atk
                                Forms\Components\Placeholder::make('kategori_atk_display')
                                    ->label('Kategori')
                                    ->content(function (Get $get) {
                                        $atkId = $get('atk_id');
                                        if ($atkId) {
                                            $atk = Atk::find($atkId);
                                            // Pastikan relasi kategoriAtk sudah di-eager load atau diakses
                                            /** @var \App\Models\KategoriAtk|null $kategoriAtk */
                                            $kategori = $atk->kategoriAtk->nama_kategori ?? 'Tidak Berkategori';
                                        }
                                        return 'Pilih ATK Dahulu';
                                    })
                                    ->columnSpan(['md' => 2]) // Sesuaikan lebar kolom
                                    ->visible(fn(string $operation) => $operation !== 'view'), // Hanya tampilkan di mode create/edit
                            ])
                            ->columns(10) // Sesuaikan jumlah kolom total di repeater
                            ->defaultItems(fn(string $operation) => $operation === 'create' ? 1 : 0)
                            ->addActionLabel('Tambah Item ATK')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn(Get $get, Set $set) => self::updateTotalPrice($get, $set))
                            ->deleteAction(
                                fn(FormComponentAction $action) => $action
                                    ->after(fn(Get $get, Set $set) => self::updateTotalPrice($get, $set))
                                    ->requiresConfirmation()
                            )
                            ->reorderable(false)->columnSpanFull()->hiddenOn('view'),
                        Placeholder::make('items_view_display')
                            ->label(fn(string $operation) => $operation === 'view' ? 'Rincian Item Dipesan' : '')
                            ->content(function (?Pesanan $record): HtmlString {
                                if (!$record || $record->items->isEmpty()) {
                                    return new HtmlString('<span class="text-gray-500">Tidak ada item yang dipesan</span>');
                                }

                                $html = '<div class="space-y-2">';
                                foreach ($record->items as $atk) {
                                    $pivot = $atk->pivot;
                                    if (!$pivot)
                                        continue;

                                    $subtotal = $pivot->jumlah * $pivot->harga_saat_pesanan;
                                    $html .= '<div class="flex justify-between items-center p-2 bg-gray-50 rounded">';
                                    $html .= '<div>';
                                    $html .= '<div class="font-medium">' . htmlspecialchars($atk->nama_atk ?? 'Nama tidak tersedia') . '</div>';
                                    $html .= '<div class="text-sm text-gray-600">Kategori: ' . htmlspecialchars($atk->kategoriAtk?->nama_kategori ?? 'Tidak Berkategori') . '</div>';
                                    $html .= '</div>';
                                    $html .= '<div class="text-right">';
                                    $html .= '<div class="text-sm">' . $pivot->jumlah . ' x ' . formatFilamentRupiah($pivot->harga_saat_pesanan) . '</div>';
                                    $html .= '<div class="font-medium">' . formatFilamentRupiah($subtotal) . '</div>';
                                    $html .= '</div>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';
                                return new HtmlString($html);
                            })
                            ->columnSpanFull()
                            ->visible(fn(string $operation) => $operation === 'view'),
                    ]),
            ]);
    }

    public static function updateTotalPrice(Get $get, Set $set): void
    {
        $items = $get('items');
        $total = 0;

        if (is_array($items)) {
            foreach ($items as $item) {
                $jumlah = (int) ($item['jumlah'] ?? 0);
                $harga = (float) ($item['harga_saat_pesanan'] ?? 0);
                $total += $jumlah * $harga;
            }
        }

        $set('total_harga', $total);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable()->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tanggal_pesanan')->dateTime('d M Y, H:i')->sortable()->label('Tgl Pesan'),
                Tables\Columns\TextColumn::make('nama_pelanggan')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('total_harga')->money('IDR')->sortable()->label('Total'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn(Pesanan $record): string => $record->formatted_status)
                    ->color(fn(Pesanan $record): string => match (strtolower($record->status ?? '')) {
                        'baru', 'pending' => 'gray', 'menunggu_konfirmasi_pembayaran' => 'warning',
                        'lunas', 'lunas (pembayaran dikonfirmasi)' => 'success', 'diproses' => 'info',
                        'dikirim' => 'primary', 'selesai' => 'success', 'dibatalkan', 'batal' => 'danger',
                        default => 'gray',
                    })->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::getStatusPesananOptions()),
                Tables\Filters\SelectFilter::make('status_pembayaran')->options(self::getStatusPembayaranOptions()),
            ])
            ->actions([ViewAction::make()->iconButton()->color('gray'), EditAction::make()->iconButton(),])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make(),]),])
            ->defaultSort('tanggal_pesanan', 'desc');
    }

    // Penting: Pastikan relasi kategoriAtk di-eager load saat mengambil Pesanan
    // agar kategori bisa diakses di repeater mode view tanpa N+1 query.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['items.kategoriAtk', 'user']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPesanans::route('/'),
            'create' => Pages\CreatePesanan::route('/create'),
            'view' => Pages\ViewPesanan::route('/{record}'),
            'edit' => Pages\EditPesanan::route('/{record}/edit'),
        ];
    }
}