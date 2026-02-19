# Laravel/Filament Patterns Reference

Specific implementation patterns for Laravel 12, Filament 3, and related technologies.

---

## Technology Stack

### Core Framework
- **Laravel 12.x** - PHP framework
- **Filament 3.x** - Admin panel framework
- **Livewire 3.x** - Full-stack framework
- **React 18** - For customer portals (optional)
- **Tailwind CSS 3.x** - Utility-first CSS

### Database
- **MySQL 8.x** - Relational database
- **AWS Aurora MySQL** - Production (AWS)
- **Redis** - Caching and queues

### AWS Services
- **S3** - File storage
- **SES** - Email sending
- **Lambda** - Serverless functions
- **Aurora** - Managed database
- **SQS** - Message queues
- **ElastiCache** - Redis cache

---

## Project Structure

```
laravel-project/
├── app/
│   ├── Models/                  # Eloquent models
│   │   ├── GiftCard.php
│   │   └── GiftCardTransaction.php
│   │
│   ├── Services/               # Business logic
│   │   ├── GiftCardService.php
│   │   └── FacturamaService.php
│   │
│   ├── Repositories/           # Data access layer
│   │   └── GiftCardRepository.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/           # API controllers
│   │   │       └── GiftCardController.php
│   │   ├── Requests/          # Form request validation
│   │   │   └── RedeemGiftCardRequest.php
│   │   ├── Resources/         # API resources
│   │   │   └── GiftCardResource.php
│   │   └── Middleware/
│   │
│   ├── Filament/
│   │   ├── Resources/         # CRUD resources
│   │   │   ├── GiftCardResource.php
│   │   │   └── GiftCardResource/
│   │   │       ├── Pages/
│   │   │       │   ├── CreateGiftCard.php
│   │   │       │   ├── EditGiftCard.php
│   │   │       │   └── ListGiftCards.php
│   │   │       └── RelationManagers/
│   │   │           └── TransactionsRelationManager.php
│   │   ├── Pages/             # Custom pages
│   │   └── Widgets/           # Dashboard widgets
│   │
│   ├── Jobs/                  # Queue jobs
│   │   └── GenerateCfdiInvoice.php
│   │
│   └── Events/                # Domain events
│       └── GiftCardRedeemed.php
│
├── database/
│   ├── migrations/            # Database migrations
│   └── factories/             # Model factories
│
├── tests/
│   ├── Unit/                  # Unit tests
│   └── Feature/               # Feature tests
│
└── resources/
    ├── views/                 # Blade templates
    └── js/                    # React components (if used)
```

---

## Model Pattern

### Base Model Structure

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftCard extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'amount',
        'balance',
        'expires_at',
        'pin',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'pin',
    ];

    // Relationships
    public function transactions()
    {
        return $this->hasMany(GiftCardTransaction::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->where('balance', '>', 0);
    }

    // Accessors
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at < now();
    }

    // Methods
    public function redeem(float $amount): GiftCardTransaction
    {
        if ($this->balance < $amount) {
            throw new InsufficientBalanceException();
        }

        if ($this->is_expired) {
            throw new GiftCardExpiredException();
        }

        $this->balance -= $amount;
        $this->save();

        return $this->transactions()->create([
            'type' => 'redemption',
            'amount' => $amount,
            'balance_after' => $this->balance,
        ]);
    }
}
```

---

## Service Layer Pattern

### Service Structure

```php
<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Repositories\GiftCardRepository;
use App\Services\FacturamaService;
use App\Exceptions\GiftCardException;
use Illuminate\Support\Str;

class GiftCardService
{
    public function __construct(
        private GiftCardRepository $repository,
        private FacturamaService $facturama,
    ) {}

    public function createGiftCard(array $data): GiftCard
    {
        $data['code'] = $data['code'] ?? $this->generateUniqueCode();
        $data['balance'] = $data['amount'];
        $data['status'] = 'active';

        if (!isset($data['expires_at'])) {
            $data['expires_at'] = now()->addYear();
        }

        return $this->repository->create($data);
    }

    public function redeemGiftCard(string $code, float $amount, string $pin): GiftCardTransaction
    {
        $giftCard = $this->repository->findByCode($code);

        if (!$giftCard) {
            throw new GiftCardException('Gift card not found');
        }

        if (!$giftCard->verifyPin($pin)) {
            throw new GiftCardException('Invalid PIN');
        }

        $transaction = $giftCard->redeem($amount);

        // Generate CFDI invoice if required
        if ($this->shouldGenerateCfdi($transaction)) {
            $this->facturama->generateInvoice($transaction);
        }

        event(new GiftCardRedeemed($giftCard, $transaction));

        return $transaction;
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'GC-' . now()->year . '-' . strtoupper(Str::random(8));
        } while ($this->repository->findByCode($code));

        return $code;
    }

    private function shouldGenerateCfdi(GiftCardTransaction $transaction): bool
    {
        // Business logic for CFDI generation
        return $transaction->amount >= 100;
    }
}
```

---

## Repository Pattern

### Repository Structure

```php
<?php

namespace App\Repositories;

use App\Models\GiftCard;
use Illuminate\Support\Collection;

class GiftCardRepository
{
    public function create(array $data): GiftCard
    {
        return GiftCard::create($data);
    }

    public function findByCode(string $code): ?GiftCard
    {
        return GiftCard::where('code', $code)->first();
    }

    public function findActive(): Collection
    {
        return GiftCard::active()->get();
    }

    public function findExpiringSoon(int $days = 30): Collection
    {
        return GiftCard::active()
            ->where('expires_at', '<=', now()->addDays($days))
            ->get();
    }

    public function getRedemptionStats(string $period = 'month'): array
    {
        return [
            'total_redeemed' => GiftCard::sum('amount') - GiftCard::sum('balance'),
            'active_cards' => GiftCard::active()->count(),
            'total_balance' => GiftCard::sum('balance'),
        ];
    }
}
```

---

## Filament Resource Pattern

### Complete Filament Resource

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GiftCardResource\Pages;
use App\Filament\Resources\GiftCardResource\RelationManagers;
use App\Models\GiftCard;
use App\Services\GiftCardService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class GiftCardResource extends Resource
{
    protected static ?string $model = GiftCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Tarjetas de Regalo';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información Básica')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Código')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->helperText('Dejar en blanco para generar automáticamente'),

                        Forms\Components\TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->minValue(1)
                            ->maxValue(10000),

                        Forms\Components\DatePicker::make('expires_at')
                            ->label('Fecha de Expiración')
                            ->required()
                            ->default(now()->addYear())
                            ->minDate(now()),

                        Forms\Components\TextInput::make('pin')
                            ->label('PIN')
                            ->password()
                            ->maxLength(6)
                            ->helperText('PIN de 4-6 dígitos para seguridad'),
                    ]),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options([
                                'active' => 'Activa',
                                'depleted' => 'Agotada',
                                'expired' => 'Expirada',
                                'suspended' => 'Suspendida',
                            ])
                            ->default('active')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto Original')
                    ->money('MXN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo Actual')
                    ->money('MXN')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->enum([
                        'active' => 'Activa',
                        'depleted' => 'Agotada',
                        'expired' => 'Expirada',
                        'suspended' => 'Suspendida',
                    ])
                    ->colors([
                        'success' => 'active',
                        'danger' => 'expired',
                        'warning' => 'suspended',
                        'secondary' => 'depleted',
                    ]),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'active' => 'Activa',
                        'depleted' => 'Agotada',
                        'expired' => 'Expirada',
                        'suspended' => 'Suspendida',
                    ]),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Por expirar (30 días)')
                    ->query(fn ($query) => $query->whereBetween('expires_at', [now(), now()->addDays(30)])),
            ])
            ->actions([
                Tables\Actions\Action::make('redeem')
                    ->label('Canjear')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a canjear')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0.01)
                            ->maxValue(fn ($record) => $record->balance),

                        Forms\Components\TextInput::make('pin')
                            ->label('PIN')
                            ->password()
                            ->required()
                            ->maxLength(6),
                    ])
                    ->action(function (GiftCard $record, array $data) {
                        try {
                            $service = app(GiftCardService::class);
                            $transaction = $service->redeemGiftCard(
                                $record->code,
                                $data['amount'],
                                $data['pin']
                            );

                            Notification::make()
                                ->success()
                                ->title('Tarjeta canjeada exitosamente')
                                ->body("Nuevo saldo: $" . number_format($record->fresh()->balance, 2))
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al canjear')
                                ->body($e->getMessage())
                                ->send();

                            throw $e;
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Canjear Tarjeta de Regalo')
                    ->modalDescription('Ingrese el monto a canjear y el PIN de seguridad.')
                    ->visible(fn ($record) => $record->status === 'active' && $record->balance > 0),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftCards::route('/'),
            'create' => Pages\CreateGiftCard::route('/create'),
            'view' => Pages\ViewGiftCard::route('/{record}'),
            'edit' => Pages\EditGiftCard::route('/{record}/edit'),
        ];
    }
}
```

---

## API Controller Pattern

### API Controller Structure

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RedeemGiftCardRequest;
use App\Http\Resources\GiftCardResource;
use App\Services\GiftCardService;
use Illuminate\Http\JsonResponse;

class GiftCardController extends Controller
{
    public function __construct(
        private GiftCardService $service
    ) {}

    /**
     * Redeem a gift card
     *
     * @param RedeemGiftCardRequest $request
     * @return JsonResponse
     */
    public function redeem(RedeemGiftCardRequest $request): JsonResponse
    {
        try {
            $transaction = $this->service->redeemGiftCard(
                $request->input('code'),
                $request->input('amount'),
                $request->input('pin')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'remaining_balance' => $transaction->giftCard->balance,
                    'cfdi_uuid' => $transaction->cfdi_uuid,
                ],
                'message' => 'Gift card redeemed successfully',
            ], 200);

        } catch (GiftCardException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while processing your request',
            ], 500);
        }
    }

    /**
     * Check gift card balance
     *
     * @param string $code
     * @return JsonResponse
     */
    public function balance(string $code): JsonResponse
    {
        $giftCard = $this->service->findByCode($code);

        if (!$giftCard) {
            return response()->json([
                'success' => false,
                'error' => 'Gift card not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new GiftCardResource($giftCard),
        ], 200);
    }
}
```

---

## CFDI Integration Pattern (Facturama)

### Facturama Service

```php
<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Models\GiftCardTransaction;

class FacturamaService
{
    private Client $client;
    private string $apiUrl;
    private string $username;
    private string $password;

    public function __construct()
    {
        $this->apiUrl = config('services.facturama.api_url');
        $this->username = config('services.facturama.username');
        $this->password = config('services.facturama.password');

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'auth' => [$this->username, $this->password],
            'timeout' => 30,
        ]);
    }

    public function generateInvoice(GiftCardTransaction $transaction): array
    {
        $cfdiData = $this->formatForCfdi40($transaction);

        $response = $this->client->post('api/2/cfdis', [
            'json' => $cfdiData,
        ]);

        $result = json_decode($response->getBody(), true);

        // Store CFDI UUID in transaction
        $transaction->update([
            'cfdi_uuid' => $result['Complement']['TaxStamp']['Uuid'],
            'cfdi_xml' => $result['Xml'],
            'cfdi_pdf_url' => $result['PdfUrl'],
        ]);

        return $result;
    }

    private function formatForCfdi40(GiftCardTransaction $transaction): array
    {
        $giftCard = $transaction->giftCard;

        return [
            'CfdiType' => 'I', // Ingreso
            'Serie' => 'GC',
            'Folio' => $transaction->id,
            'Date' => now()->toIso8601String(),
            'PaymentForm' => '28', // Tarjeta de regalo
            'PaymentMethod' => 'PUE', // Pago en una sola exhibición
            'Currency' => 'MXN',
            'Receiver' => [
                'Rfc' => $transaction->customer_rfc ?? 'XAXX010101000',
                'Name' => $transaction->customer_name ?? 'Público General',
                'CfdiUse' => 'G03', // Gastos en general
            ],
            'Items' => [
                [
                    'ProductCode' => '01010101', // Producto genérico
                    'Description' => "Uso de tarjeta de regalo {$giftCard->code}",
                    'Unit' => 'Servicio',
                    'UnitCode' => 'E48', // Unidad de servicio
                    'UnitPrice' => $transaction->amount,
                    'Quantity' => 1,
                    'Subtotal' => $transaction->amount,
                    'TaxObject' => '02', // Sí objeto de impuesto
                    'Taxes' => [
                        [
                            'Name' => 'IVA',
                            'Rate' => 0.16,
                            'Total' => $transaction->amount * 0.16,
                            'Base' => $transaction->amount,
                            'IsRetention' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function cancelInvoice(string $uuid, string $reason = '02'): array
    {
        $response = $this->client->delete("api/2/cfdis/{$uuid}", [
            'json' => [
                'motive' => $reason, // 02 = Comprobante emitido con errores con relación
            ],
        ]);

        return json_decode($response->getBody(), true);
    }
}
```

---

## Testing Patterns

### Model Test

```php
<?php

namespace Tests\Unit\Models;

use App\Models\GiftCard;
use App\Exceptions\InsufficientBalanceException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GiftCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_redeem_gift_card_with_sufficient_balance()
    {
        $giftCard = GiftCard::factory()->create([
            'balance' => 500.00,
        ]);

        $transaction = $giftCard->redeem(100.00);

        $this->assertEquals(400.00, $giftCard->fresh()->balance);
        $this->assertEquals(100.00, $transaction->amount);
        $this->assertEquals('redemption', $transaction->type);
    }

    public function test_cannot_redeem_more_than_balance()
    {
        $giftCard = GiftCard::factory()->create([
            'balance' => 100.00,
        ]);

        $this->expectException(InsufficientBalanceException::class);

        $giftCard->redeem(150.00);
    }

    public function test_cannot_redeem_expired_gift_card()
    {
        $giftCard = GiftCard::factory()->expired()->create();

        $this->expectException(GiftCardExpiredException::class);

        $giftCard->redeem(50.00);
    }

    public function test_active_scope_filters_correctly()
    {
        GiftCard::factory()->create(['status' => 'active', 'balance' => 100]);
        GiftCard::factory()->create(['status' => 'depleted']);
        GiftCard::factory()->expired()->create();

        $active = GiftCard::active()->get();

        $this->assertCount(1, $active);
    }
}
```

### API Test

```php
<?php

namespace Tests\Feature\Api;

use App\Models\GiftCard;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GiftCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_redeem_gift_card_via_api()
    {
        $giftCard = GiftCard::factory()->create([
            'code' => 'TEST-2024-ABC123',
            'balance' => 500.00,
            'pin' => '1234',
        ]);

        $response = $this->postJson('/api/v1/gift-cards/redeem', [
            'code' => 'TEST-2024-ABC123',
            'amount' => 100.00,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'remaining_balance' => 400.00,
                ],
            ]);

        $this->assertDatabaseHas('gift_cards', [
            'code' => 'TEST-2024-ABC123',
            'balance' => 400.00,
        ]);
    }

    public function test_cannot_redeem_with_invalid_pin()
    {
        $giftCard = GiftCard::factory()->create([
            'code' => 'TEST-2024-ABC123',
            'pin' => '1234',
        ]);

        $response = $this->postJson('/api/v1/gift-cards/redeem', [
            'code' => 'TEST-2024-ABC123',
            'amount' => 100.00,
            'pin' => 'wrong',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_rate_limiting_prevents_brute_force()
    {
        $giftCard = GiftCard::factory()->create();

        // Make 11 requests (rate limit is 10/minute)
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/v1/gift-cards/redeem', [
                'code' => $giftCard->code,
                'amount' => 10.00,
                'pin' => 'test',
            ]);
        }

        $response->assertStatus(429); // Too Many Requests
    }
}
```

---

## Configuration Examples

### Services Config

```php
// config/services.php

return [
    'facturama' => [
        'api_url' => env('FACTURAMA_API_URL', 'https://api.facturama.mx/'),
        'username' => env('FACTURAMA_USERNAME'),
        'password' => env('FACTURAMA_PASSWORD'),
    ],

    'sap' => [
        'service_layer_url' => env('SAP_SERVICE_LAYER_URL'),
        'username' => env('SAP_USERNAME'),
        'password' => env('SAP_PASSWORD'),
        'company_db' => env('SAP_COMPANY_DB'),
    ],
];
```

---

## Summary

These patterns provide production-ready implementations for:

- ✅ Laravel models with relationships, scopes, and methods
- ✅ Service layer for business logic
- ✅ Repository pattern for data access
- ✅ Filament resources with custom actions
- ✅ API controllers with proper error handling
- ✅ CFDI 4.0 integration via Facturama
- ✅ Comprehensive testing patterns

All patterns follow Laravel best practices and are optimized for DevTeam's agent-based workflow.
