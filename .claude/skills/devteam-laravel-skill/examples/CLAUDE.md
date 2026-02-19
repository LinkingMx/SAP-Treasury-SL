# [Project Name] - Context for Claude

## Overview
Brief description of what this Laravel project does.

---

## Technology Stack

### Core
- Laravel 12.x
- PHP 8.2+
- MySQL 8.x / AWS Aurora
- Redis 6.x+

### Frontend
- Filament 3.x (Admin panels)
- Livewire 3.x
- React 18 (if customer portal)
- Tailwind CSS 3.x

### Infrastructure
- AWS Elastic Beanstalk / ECS
- AWS S3 (file storage)
- AWS SES (emails)
- AWS Lambda (serverless functions)
- ElastiCache Redis

---

## Architecture Patterns

### Code Organization
- **Repository Pattern**: Data access layer in `app/Repositories/`
- **Service Layer**: Business logic in `app/Services/`
- **Events**: Domain events in `app/Events/`
- **Jobs**: Async processing in `app/Jobs/`

### API Design
- RESTful endpoints at `/api/v1/*`
- JSON responses using API Resources
- Token-based authentication (Sanctum)
- Rate limiting: 60 requests/minute

---

## Key Integrations

### Facturama (CFDI 4.0 Electronic Invoicing)
- API URL: https://api.facturama.mx/
- Used for: Electronic invoice generation (timbrado)
- PAC certification requirements apply
- Invoice cancellation workflow required
- SAT catalog validations mandatory

### SAP Business One (if applicable)
- Service Layer REST API
- Used for: Inventory sync, invoice sync
- Session timeout: 20 minutes
- Data mapping documented in `/docs/sap-integration.md`

### AWS Services
- **S3**: Document storage, QR code images
- **SES**: Transactional emails
- **Lambda**: Scheduled reports, data processing
- **SQS**: Background job queues

---

## Coding Standards

### PHP/Laravel
- **PSR-12** compliance mandatory
- Use **Spatie packages** for common needs:
  - `spatie/laravel-permission` for roles/permissions
  - `spatie/laravel-query-builder` for API filtering
  - `spatie/laravel-activitylog` for audit trails
- Follow **Laravel naming conventions**
- Use **type hints** and **return types** everywhere
- Write **PHPDoc** for complex methods

### Filament
- Resource names in **singular** (GiftCardResource not GiftCardsResource)
- Group related resources: `protected static ?string $navigationGroup = 'Ventas';`
- Use **custom actions** for business operations
- Implement **relation managers** for has-many relationships

### Testing
- **Minimum 80% code coverage**
- Use **factories** for test data
- Mock external APIs (Facturama, SAP)
- Use **Pest** for feature tests
- Use **PHPUnit** for unit tests

---

## File Structure

```
app/
├── Models/              # Eloquent models
├── Services/           # Business logic
├── Repositories/       # Data access
├── Http/
│   ├── Controllers/
│   │   └── Api/       # API endpoints
│   ├── Requests/      # Form validation
│   ├── Resources/     # API transformers
│   └── Middleware/
├── Filament/
│   ├── Resources/     # Admin CRUD
│   ├── Pages/         # Custom pages
│   └── Widgets/       # Dashboard widgets
├── Jobs/              # Queue jobs
└── Events/            # Domain events
```

---

## Database Conventions

### Naming
- Table names: **plural**, **snake_case** (e.g., `gift_cards`)
- Column names: **snake_case** (e.g., `created_at`)
- Foreign keys: **{table_singular}_id** (e.g., `user_id`)
- Pivot tables: **alphabetical order** (e.g., `product_user` not `user_product`)

### Migrations
- Use **timestamps**: `$table->timestamps();`
- Use **soft deletes**: `$table->softDeletes();` when appropriate
- Add **indexes** for foreign keys and frequently queried columns
- Never edit existing migrations, create new ones

---

## CFDI Compliance (Mexican Electronic Invoicing)

### Requirements
- CFDI 4.0 standard (current as of 2024)
- PAC: Facturama for timbrado
- SAT catalogs up to date
- Invoice cancellation within 72 hours
- Monthly XML backup to S3

### Implementation
- Service: `App\Services\FacturamaService`
- Models: Include `cfdi_uuid`, `cfdi_xml`, `cfdi_pdf_url` fields
- Jobs: `GenerateCfdiInvoice`, `CancelCfdiInvoice`
- Validation: SAT catalog codes (forms of payment, tax regimes, etc.)

---

## Common Patterns

### Service Constructor Injection

```php
class GiftCardService
{
    public function __construct(
        private GiftCardRepository $repository,
        private FacturamaService $facturama,
    ) {}
}
```

### Filament Custom Actions

```php
Tables\Actions\Action::make('redeem')
    ->icon('heroicon-o-currency-dollar')
    ->form([/* ... */])
    ->action(fn ($record, $data) => /* ... */)
    ->requiresConfirmation();
```

### API Response Format

```php
return response()->json([
    'success' => true,
    'data' => $resource,
    'message' => 'Operation completed successfully',
], 200);
```

---

## Environment Variables Pattern

```env
# Application
APP_NAME="Project Name"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://example.com

# Database
DB_CONNECTION=mysql
DB_HOST=aurora-cluster.region.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=production_db
DB_USERNAME=dbuser
DB_PASSWORD=

# Redis
REDIS_HOST=cache.region.cache.amazonaws.com
REDIS_PASSWORD=
REDIS_PORT=6379

# AWS
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=project-bucket

# Facturama
FACTURAMA_API_URL=https://api.facturama.mx/
FACTURAMA_USERNAME=
FACTURAMA_PASSWORD=

# SAP Business One
SAP_SERVICE_LAYER_URL=https://sap-server:50000/b1s/v1/
SAP_USERNAME=
SAP_PASSWORD=
SAP_COMPANY_DB=

# Mail
MAIL_MAILER=ses
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

---

## What NOT to Do (Anti-Patterns)

### Database
- ❌ Don't use UUIDs as primary keys (use auto-increment integers)
- ❌ Don't store sensitive data unencrypted (use Laravel encryption)
- ❌ Don't use `SELECT *` in queries (specify columns)

### Code Organization
- ❌ Don't put business logic in controllers (use Services)
- ❌ Don't put complex queries in models (use Repositories)
- ❌ Don't use static methods unless absolutely necessary

### Filament
- ❌ Don't create separate pages for simple actions (use custom table actions)
- ❌ Don't duplicate resource logic (use traits or base classes)

### APIs
- ❌ Don't return models directly (use API Resources)
- ❌ Don't skip validation (use Form Requests)
- ❌ Don't use GET for state-changing operations (use POST/PUT/DELETE)

### Testing
- ❌ Don't test framework features (test your logic)
- ❌ Don't hit real APIs in tests (use mocks/VCR)
- ❌ Don't skip edge cases (expired cards, insufficient balance, etc.)

---

## Common Gotchas

### Facturama
- **Sandbox vs Production**: Different API URLs and credentials
- **Rate Limits**: 100 requests/minute
- **Session Timeout**: Re-authenticate if requests fail
- **SAT Catalogs**: Must be kept up to date

### SAP Business One
- **Session Timeout**: 20 minutes, need to re-login
- **Date Format**: ISO 8601 (YYYY-MM-DD)
- **Decimal Precision**: 2 decimal places for amounts
- **Company Database**: Must specify in login

### AWS Aurora
- **Connection Pooling**: Configure in Laravel queue workers
- **Read Replicas**: Use for reporting queries
- **Timezone**: Always UTC in database, convert in application

### Filament
- **Form State**: Use `fillForm()` to set initial values
- **Table Filters**: Remember they persist across page refreshes
- **Custom Pages**: Must register in `getPages()` array

---

## Deployment Process

### Pre-Deployment Checklist
- [ ] All tests passing
- [ ] Code coverage >= 80%
- [ ] Migrations reviewed
- [ ] Environment variables configured
- [ ] Queue workers configured
- [ ] Cron jobs scheduled

### Deployment Steps
1. Pull latest code
2. Run migrations: `php artisan migrate --force`
3. Clear caches: `php artisan optimize:clear`
4. Build assets: `npm run build`
5. Restart queue workers
6. Run smoke tests

---

## Performance Optimization

### Database
- Use **eager loading** to prevent N+1 queries
- Add **indexes** on frequently queried columns
- Use **database transactions** for multi-step operations
- Use **chunking** for large dataset processing

### Caching
- Cache frequently accessed data (configurations, lookups)
- Use **Redis** for session and cache storage
- Cache Filament resources if appropriate
- Clear caches after deployments

### Queues
- Use **queues** for slow operations (CFDI generation, emails)
- Use **job batching** for bulk operations
- Monitor queue failures
- Set appropriate timeouts

---

## Monitoring & Logging

### What to Log
- CFDI generation (success/failure)
- SAP API calls
- Payment processing
- User authentication
- Failed jobs

### What to Monitor
- Queue depth and processing time
- API response times
- Database query performance
- S3 storage usage
- Error rates

---

## Security Considerations

### Authentication
- Use **Laravel Sanctum** for API tokens
- Implement **2FA** for admin users
- Use **strong passwords** (min 12 chars)
- Expire sessions after inactivity

### Authorization
- Use **Spatie Permission** for roles
- Implement **policy classes** for model authorization
- Use **Gates** for feature flags
- Audit all permission changes

### Data Protection
- **Encrypt** sensitive fields (PINs, SSNs, etc.)
- Use **HTTPS** everywhere
- Implement **CORS** properly
- Sanitize all user inputs
- Use **prepared statements** (Eloquent does this)

---

## Lessons Learned (Update After Each Project)

### 2024-02-07: Gift Card System
- Facturama: Use VCR recordings in tests to avoid rate limits
- QR codes: Store as S3 URLs, not binary in database
- Filament: Custom actions better than separate pages for workflows

### 2024-02-05: Travel Expense System
- CFDI validation: Do async via job, not in controller
- File uploads: Use S3 pre-signed URLs for large files
- Filament: Use relation managers for has-many relationships

---

## Support & Resources

### Documentation
- Laravel Docs: https://laravel.com/docs/12.x
- Filament Docs: https://filamentphp.com/docs
- Facturama API: https://api.facturama.mx/docs
- SAP Business One Service Layer: [internal docs]

### Internal Resources
- Architecture diagrams: `/docs/architecture/`
- API documentation: `/docs/api/`
- Deployment runbooks: `/docs/deployment/`

---

## Contact & Escalation

### Technical Contacts
- Lead Developer: [name] ([email])
- DevOps: [name] ([email])
- Facturama Support: soporte@facturama.mx
- SAP Partner: [partner contact]

---

**Last Updated**: 2024-02-07  
**Maintained By**: Development Team
