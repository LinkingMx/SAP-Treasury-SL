# Advanced Patterns

Advanced usage patterns for power users.

---

## Custom Agent Configurations

### Specialized Agent Roles

Customize agents for project-specific needs:

```
"For development phase, spawn these agents:

1. Laravel Backend Engineer (backend)
   - Expertise: Laravel, MySQL, Redis
   - Focus: Models, Services, Repositories, Jobs
   - Files: app/Models/, app/Services/, app/Jobs/

2. Filament Frontend Engineer (frontend)
   - Expertise: Filament 3, Livewire, Blade
   - Focus: Resources, Pages, Widgets, Custom actions
   - Files: app/Filament/, resources/views/

3. CFDI Integration Specialist (cfdi)
   - Expertise: Mexican tax law, Facturama API
   - Focus: Invoice generation, SAT compliance
   - Files: app/Services/FacturamaService.php, related tests

4. Integration Coordinator (integration)
   - Expertise: API design, middleware, testing
   - Focus: Routes, middleware, integration tests
   - Files: routes/, tests/Feature/"
```

---

### Domain Expert Agents

For specific business domains:

```
Phase 1: Planning with domain expert

Agents:
- Solution Architect (technical design)
- FinTech Expert (payment compliance)
- Security Auditor (PCI DSS, GDPR)
```

---

## Custom Phase Insertion

### Security Audit Phase

Insert between Development and Testing:

```
Phase 2: Development
    ↓
Phase 2.5: Security Audit
  - SQL injection testing
  - XSS vulnerability scanning
  - Authentication bypass attempts
  - Rate limiting verification
    ↓
Phase 3: Testing
```

**Prompt**:
```
"After development but before testing, insert a Security Audit phase.

Spawn 2 security specialists:
1. OWASP Tester - Check for OWASP Top 10 vulnerabilities
2. Penetration Tester - Attempt to bypass authentication, access controls

Deliverables:
- SECURITY_AUDIT_REPORT.md
- List of vulnerabilities found (if any)
- Recommended fixes
- Retest results after fixes"
```

---

### Performance Optimization Phase

Insert before Documentation:

```
Phase 3: Testing
    ↓
Phase 3.5: Performance Optimization
  - Query optimization
  - Caching strategy
  - API response time improvement
  - Database indexing
    ↓
Phase 4: Documentation
```

---

## Iterative Development

### MVP → Enhancement Iterations

**Iteration 1: MVP**
```
"Build minimal viable gift card system:
- Core: Card creation, redemption, balance check
- Admin: Basic CRUD in Filament
- Skip: CFDI, advanced features"

Phases: Planning → Development → Testing → Documentation
Result: Working MVP in 2-3 hours
```

**Iteration 2: CFDI Integration**
```
"Add CFDI invoicing to existing gift card system:
- Integrate Facturama API
- Async job for invoice generation
- Store UUID, XML, PDF in database

Starting point: Code from Iteration 1
Phases: Planning (update specs) → Development → Testing → Documentation (update)"
```

**Iteration 3: Mobile Wallet**
```
"Add Apple Wallet / Google Pay integration:
- Generate pass files
- Handle pass updates
- QR code for redemption

Starting point: Code from Iteration 2"
```

---

### Feature Flag Pattern

```
"Build feature with flag for gradual rollout:

1. Implement feature fully
2. Wrap in feature flag check
3. Default: disabled
4. Admin toggle in Filament

Code example:
if (Feature::active('gift-card-redemption-v2')) {
    // New redemption logic
} else {
    // Old redemption logic
}
```

---

## Hybrid Workflows

### DevTeam + Manual Coding

**Pattern**: DevTeam for structure, manual for complex logic

```
Phase 1: Planning (DevTeam)
  → Get specs, architecture

Phase 2: Development (Partial)
  → DevTeam: Models, migrations, basic Filament
  → Manual: Complex validation logic
  → DevTeam: Continue with integration

Phase 3: Testing (DevTeam)
  → Full test suite

Phase 4: Documentation (DevTeam)
```

---

### DevTeam + Code Review

**Pattern**: Generate code, then review before approval

```
Phase 2: Development
  → DevTeam generates code
  → Checkpoint: Review code
  → Run: php-cs-fixer, phpstan, tests
  → If issues: Request fixes
  → If good: Approve, continue
```

---

## Multi-Project Patterns

### Microservices Architecture

Each service gets own DevTeam workflow:

```
Service 1: User Service
  → DevTeam builds: User auth, profile management
  → Deliverable: Standalone Laravel API

Service 2: Product Service
  → DevTeam builds: Product catalog, inventory
  → Deliverable: Standalone Laravel API

Service 3: Order Service
  → DevTeam builds: Order processing, payments
  → Deliverable: Standalone Laravel API
  → Integration: Calls Service 1 & 2 APIs
```

---

### Monorepo Pattern

Single repo, multiple DevTeam workflows:

```
laravel-monorepo/
├── packages/
│   ├── user-management/    (DevTeam workflow 1)
│   ├── product-catalog/    (DevTeam workflow 2)
│   └── order-processing/   (DevTeam workflow 3)
└── app/
    └── main-application/   (Integrates packages)
```

---

## Advanced Testing Patterns

### Mutation Testing

Add to Testing phase:

```
"In testing phase, after standard tests:

1. Run mutation testing with Infection PHP
2. Identify untested code paths
3. Write additional tests for mutations that survive
4. Target: 80%+ Mutation Score Indicator (MSI)"
```

---

### Contract Testing

For API integrations:

```
"Testing phase for payment service:

1. Unit tests (standard)
2. Integration tests (standard)
3. Contract tests:
   - Define Pact contracts for Stripe API
   - Verify our code matches contract
   - Verify Stripe responses match contract
   - Catch breaking changes early"
```

---

## Compliance-Driven Development

### GDPR Compliance Pattern

```
Phase 1: Planning
  → Security agent focuses on GDPR requirements
  → Deliverable: GDPR_COMPLIANCE.md

Phase 2: Development
  → Implement: Right to erasure, data portability, consent
  → Audit logging for data access

Phase 3: Testing
  → Test GDPR workflows
  → Verify data deletion actually deletes

Phase 4: Documentation
  → Privacy policy
  → Data processing documentation
```

---

### PCI DSS Compliance

For payment card handling:

```
Phase 1: Planning
  → PCI DSS requirements mapping
  → Tokenization strategy
  → No card storage decision

Phase 2: Development
  → Stripe integration (never touch card data)
  → PCI-compliant logging
  → Secure transmission only

Phase 3: Testing
  → Verify no card data in logs
  → Test encrypted transmission
  → Audit trail verification
```

---

## Database Migration Patterns

### Zero-Downtime Migrations

```
"Development phase: implement zero-downtime migration

Strategy:
1. Add new column (nullable)
2. Dual-write: Write to both old and new
3. Backfill old rows
4. Switch reads to new column
5. Remove old column (separate deployment)

Deliverable: 5 separate migration files with rollback plans"
```

---

### Multi-Tenant Patterns

```
Phase 1: Planning
  → Decide: Schema per tenant vs Shared schema with tenant_id
  → Design tenant isolation strategy

Phase 2: Development
  → Implement tenant scoping in models
  → Middleware for tenant context
  → Filament multi-tenancy support

Phase 3: Testing
  → Test tenant isolation (Tenant A can't see Tenant B data)
  → Test cross-tenant queries properly blocked
```

---

## API Versioning

### Versioned API Development

```
"Build API with versioning support:

Phase 1: Planning
  → API versioning strategy (URL-based: /api/v1/, /api/v2/)
  → Backward compatibility plan

Phase 2: Development
  → Namespace controllers by version
  ├── app/Http/Controllers/Api/V1/
  └── app/Http/Controllers/Api/V2/
  → Version-specific routes
  → Shared logic in services

Phase 4: Documentation
  → Document both v1 and v2
  → Migration guide from v1 to v2"
```

---

## Background Job Patterns

### Job Chain Pattern

```
"Implement gift card purchase workflow as job chain:

Job 1: CreateGiftCard
  → Create card record
  → Generate code and PIN
  ↓
Job 2: GenerateQrCode (after Job 1)
  → Create QR code image
  → Upload to S3
  ↓
Job 3: SendConfirmationEmail (after Job 2)
  → Email with card details
  → Include QR code attachment
  ↓
Job 4: GenerateCfdiInvoice (after Job 3)
  → Call Facturama API
  → Store UUID and XML

Handle failures at each step with retries and alerts"
```

---

## Event-Driven Architecture

### Event Sourcing Pattern

```
Phase 1: Planning
  → Design event stream
  → Define aggregate roots
  → Plan projections

Phase 2: Development
  → Events: GiftCardCreated, GiftCardRedeemed, GiftCardExpired
  → Event store (events table)
  → Projections (read models)
  → Event handlers

Phase 3: Testing
  → Event replay tests
  → Projection rebuilding
  → Event ordering verification
```

---

## Localization Patterns

### Multi-Language Support

```
"Build system with English and Spanish support:

Phase 2: Development
  → Use Laravel localization
  → Translation files: en/, es/
  → Filament: Locale switching
  → API: Accept-Language header

Phase 3: Testing
  → Test both languages
  → Verify translations complete
  → Test date/number formatting

Phase 4: Documentation
  → English and Spanish versions
  → Translation maintenance guide"
```

---

## Monitoring & Observability

### Instrumentation Pattern

```
"Add comprehensive monitoring:

Phase 2: Development
  → Laravel Telescope for local debugging
  → Sentry for error tracking
  → Custom metrics: Gift cards created, redeemed
  → Performance timing for CFDI generation

Phase 3: Testing
  → Verify metrics collected
  → Test alert thresholds
  → Simulate errors to test Sentry

Phase 4: Documentation
  → Monitoring dashboard guide
  → Alert runbook"
```

---

## A/B Testing Pattern

```
"Implement A/B testing for redemption flow:

Phase 2: Development
  → Variant A: Current flow
  → Variant B: Simplified flow
  → Random assignment (50/50)
  → Track conversion rates
  → Middleware for variant assignment

Phase 3: Testing
  → Test both variants
  → Verify metrics collected
  → Test assignment distribution

Phase 4: Documentation
  → A/B test analysis guide
  → How to declare winner"
```

---

## Legacy System Integration

### Strangler Fig Pattern

```
"Migrate from legacy PHP 7 system to Laravel 12:

Iteration 1: Build new Laravel app with DevTeam
  → New gift card creation (new system)
  → Redemption still uses legacy system

Iteration 2: Migrate redemption
  → New redemption logic
  → Sync state between old and new

Iteration 3: Decomission legacy
  → All traffic to new system
  → Remove legacy integrations"
```

---

## Summary

Advanced patterns enable:

- ✅ Custom agent specializations
- ✅ Additional phase insertion
- ✅ Iterative development
- ✅ Hybrid workflows
- ✅ Multi-project architectures
- ✅ Advanced testing strategies
- ✅ Compliance-driven development
- ✅ Complex data patterns
- ✅ Event-driven architectures
- ✅ Modern observability

**Key principle**: DevTeam is flexible—adapt to your needs

---

**Last Updated**: 2026-02-07
