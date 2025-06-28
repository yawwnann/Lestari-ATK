<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PesananResource\Pages;
use App\Models\Pesanan;
use App\Models\Pupuk;
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
use Illuminate\Database\Eloquent\Builder; // Import untuk eager loading

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
                        ]),
                ]),

                Section::make('Item Pupuk Dipesan')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label(fn(string $operation) => $operation === 'view' ? '' : 'Item Pupuk')
                            // HAPUS BARIS INI: ->relationship()
                            // Karena Anda menangani attach/sync secara manual di CreatePesanan.php dan EditPesanan.php,
                            // menghapus ini akan mencegah Filament mencoba menyimpan model Pupuk yang tidak lengkap.
                            ->schema([
                                Forms\Components\Select::make('pupuk_id')->label('Pilih Pupuk')
                                    ->options(function (Get $get) {
                                        $currentItems = $get('../../items') ?? [];
                                        $existingPupukIdsInRepeater = collect($currentItems)->pluck('pupuk_id')->filter()->all();
                                        return Pupuk::query()
                                            ->where('stok', '>', 0)
                                            ->orWhereIn('id', $existingPupukIdsInRepeater)
                                            ->orderBy('nama_pupuk')
                                            ->pluck('nama_pupuk', 'id');
                                    })
                                    ->required()->reactive()->searchable()->preload()
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        $pupuk = Pupuk::find($state);
                                        $set('harga_saat_pesanan', $pupuk?->harga ?? 0);
                                        // Set nilai untuk placeholder kategori
                                        $set('kategori_pupuk_display', $pupuk->kategoriAtk->nama_kategori ?? 'Tidak Berkategori');
                                    })
                                    ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(['md' => 4]), // Kolom untuk pilih pupuk

                                Forms\Components\TextInput::make('jumlah')->label('Jumlah')->numeric()->required()->minValue(1)->default(1)->reactive()
                                    ->columnSpan(['md' => 2]), // Kolom untuk jumlah

                                Forms\Components\TextInput::make('harga_saat_pesanan')->label('Harga Satuan')->numeric()->prefix('Rp')->required()
                                    ->disabled()->dehydrated()
                                    ->columnSpan(['md' => 2]), // Kolom untuk harga satuan

                                // Tambahkan placeholder untuk menampilkan kategori pupuk
                                Forms\Components\Placeholder::make('kategori_pupuk_display')
                                    ->label('Kategori')
                                    ->content(function (Get $get) {
                                        $pupukId = $get('pupuk_id');
                                        if ($pupukId) {
                                            $pupuk = Pupuk::find($pupukId);
                                            // Pastikan relasi kategoriPupuk sudah di-eager load atau diakses
                                            return $pupuk->kategoriAtk->nama_kategori ?? 'Tidak Berkategori';
                                        }
                                        return 'Pilih Pupuk Dahulu';
                                    })
                                    ->columnSpan(['md' => 2]) // Sesuaikan lebar kolom
                                    ->visible(fn(string $operation) => $operation !== 'view'), // Hanya tampilkan di mode create/edit
                            ])
                            ->columns(10) // Sesuaikan jumlah kolom total di repeater
                            ->defaultItems(fn(string $operation) => $operation === 'create' ? 1 : 0)
                            ->addActionLabel('Tambah Item Pupuk')
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
                                    return new HtmlString('<div class="text-sm text-gray-500 dark:text-gray-400 italic py-2">Tidak ada item dalam pesanan ini.</div>');
                                }
                                $html = '<ul class="mt-2 border border-gray-200 dark:border-white/10 rounded-md divide-y divide-gray-200 dark:divide-white/10">';
                                /** @var \App\Models\Pupuk $itemPupuk */
                                foreach ($record->items as $itemPupuk) {
                                    $namaProduk = e($itemPupuk->nama_pupuk);
                                    $jumlah = e($itemPupuk->pivot->jumlah);
                                    $harga = formatFilamentRupiah($itemPupuk->pivot->harga_saat_pesanan);
                                    $subtotal = formatFilamentRupiah($itemPupuk->pivot->jumlah * $itemPupuk->pivot->harga_saat_pesanan);
                                    $gambarUrl = $itemPupuk->gambar_utama ?? asset('images/placeholder_small.png');
                                    // Tampilkan kategori jika ada (pastikan relasi kategoriPupuk di Pupuk.php sudah benar)
                                    $kategori = $itemPupuk->kategoriAtk->nama_kategori ?? 'Tidak Berkategori';


                                    $html .= "<li class=\"flex items-center justify-between py-3 px-4 text-sm hover:bg-gray-50 dark:hover:bg-white/5\">";
                                    $html .= "<div class=\"flex items-center\"><img src=\"{$gambarUrl}\" alt=\"{$namaProduk}\" class=\"w-10 h-10 rounded-md object-cover mr-3 flex-shrink-0\"/>";
                                    $html .= "<div><span class=\"font-medium text-gray-900 dark:text-white\">{$namaProduk}</span><br><span class=\"text-gray-500 dark:text-gray-400\">{$jumlah} x {$harga} ({$kategori})</span></div></div>"; // Tambahkan kategori di sini
                                    $html .= "<span class=\"font-medium text-gray-900 dark:text-white\">{$subtotal}</span></li>";
                                }
                                $html .= '</ul>';
                                return new HtmlString($html);
                            })->visibleOn('view')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function updateTotalPrice(Get $get, Set $set): void
    {
        $itemsData = $get('items') ?? [];
        $total = 0;
        foreach ($itemsData as $item) {
            $jumlah = $item['jumlah'] ?? 0;
            $harga = $item['harga_saat_pesanan'] ?? 0;
            if (is_numeric($jumlah) && is_numeric($harga)) {
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

    // Penting: Pastikan relasi kategoriPupuk di-eager load saat mengambil Pesanan
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