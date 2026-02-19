# DevTeam Workflow Reference

Complete reference for the 4-phase development workflow.

---

## Overview

DevTeam executes development in 4 sequential phases, with specialized agent teams collaborating in parallel within each phase.

```
Phase 1: PLANNING
  ├─ Architect Agent
  ├─ Business Analyst Agent
  └─ Security Specialist Agent
       ↓ [User Approval]

Phase 2: DEVELOPMENT  
  ├─ Backend Engineer Agent
  ├─ Frontend Engineer Agent
  └─ Integration Specialist Agent
       ↓ [User Approval]

Phase 3: TESTING
  ├─ Unit Test Engineer Agent
  ├─ Integration Tester Agent
  └─ End-to-End Tester Agent
       ↓ [User Approval]

Phase 4: DOCUMENTATION
  ├─ API Documentor Agent
  ├─ Technical Writer Agent
  └─ User Guide Writer Agent
       ↓ [Complete]
```

---

## Phase 1: Planning & Architecture

### Objective
Define comprehensive technical specifications, system architecture, and implementation requirements.

### Agent Team Structure

**3 Specialized Agents:**

1. **Solution Architect** (teammate name: `architect`)
   - Design overall system architecture
   - Define component boundaries
   - Create data flow diagrams
   - Specify technology stack
   - Design database schema
   - Define API contracts
   - Consider scalability

2. **Business Analyst** (teammate name: `business`)
   - Document user stories
   - Define acceptance criteria
   - Identify business rules
   - Document edge cases
   - Define non-functional requirements
   - Create process flows

3. **Security Specialist** (teammate name: `security`)
   - Define authentication/authorization
   - Document compliance needs (CFDI 4.0, etc.)
   - Specify data protection
   - Identify vulnerabilities
   - Define API security
   - Document audit logging

### Task Breakdown Pattern

```
Planning Tasks:
1. Analyze requirements and identify unknowns (architect)
2. Document functional requirements (business) [blocked by #1]
3. Define security requirements (security) [blocked by #1]
4. Design system architecture (architect) [blocked by #2, #3]
5. Create database schema (architect) [blocked by #4]
6. Define API contracts (architect) [blocked by #4]
7. Document business rules (business) [blocked by #5]
8. Review and debate assumptions (all agents)
9. Synthesize planning documents (architect leads)
```

### Collaboration Pattern

Agents debate and challenge each other:

```
architect → business: "You specified 'expires after 1 year'. 
                       From activation or purchase date?"

business → architect: "Purchase date. Handle timezone differences
                       for multi-location clients."

security → architect: "For redemption API, recommend rate limiting
                       of 10 requests/min per card."

architect → security: "Agreed. Adding to API specification."
```

### Deliverables

```
/planning/
├── REQUIREMENTS.md       # Functional & non-functional requirements
├── ARCHITECTURE.md       # System design, components, data flow
├── DATA_MODEL.md        # Database schema, relationships
├── API_CONTRACTS.md     # Endpoint specifications
├── SECURITY.md          # Security requirements & approach
└── TECH_STACK.md        # Technologies, libraries, versions
```

### Effort Level
**HIGH** - Complex architectural decisions require deep reasoning.

### Duration
20-40 minutes of agent collaboration

### Checkpoint
User reviews documents, asks questions, approves to proceed to Development.

---

## Phase 2: Development & Implementation

### Objective
Implement the system according to Phase 1 specifications, following Laravel and Filament best practices.

### Agent Team Structure

**3 Specialized Agents:**

1. **Backend Engineer** (teammate name: `backend`)
   - File Ownership: `app/Models/`, `app/Services/`, `app/Repositories/`, `app/Http/Controllers/Api/`, `database/migrations/`
   - Create Laravel models
   - Implement service layer
   - Build API controllers
   - Create migrations
   - Integrate external APIs
   - Write model unit tests

2. **Frontend Engineer** (teammate name: `frontend`)
   - File Ownership: `app/Filament/`, `resources/views/`, `resources/js/`
   - Create Filament resources
   - Build custom Filament pages
   - Implement Livewire components
   - Create React components (if needed)
   - Style with Tailwind CSS
   - Implement validation

3. **Integration Specialist** (teammate name: `integration`)
   - File Ownership: `routes/`, `tests/Feature/`, `app/Http/Middleware/`
   - Define routes
   - Connect backend to frontend
   - Implement middleware
   - Handle error scenarios
   - Write integration tests
   - Test external APIs

### File Ownership Strategy

**CRITICAL**: Prevent merge conflicts through clear ownership.

```
Backend owns:
├── app/Models/
├── app/Services/
├── app/Repositories/
├── app/Http/Controllers/Api/
├── database/migrations/
└── tests/Unit/

Frontend owns:
├── app/Filament/Resources/
├── app/Filament/Pages/
├── app/Filament/Widgets/
├── resources/views/
└── resources/js/

Integration owns:
├── routes/
├── app/Http/Middleware/
├── app/Http/Resources/
└── tests/Feature/
```

### Task Breakdown Pattern

```
Development Tasks:

Foundation:
1. Set up project structure (backend)
2. Create database migrations (backend)
3. Implement base models (backend) [blocked by #2]
4. Set up Filament structure (frontend)

Core Implementation:
5. Implement service layer (backend) [blocked by #3]
6. Build API controllers (backend) [blocked by #5]
7. Create Filament resources (frontend) [blocked by #3]
8. Implement custom actions (frontend) [blocked by #7]

External Integrations:
9. Integrate Facturama API (backend) [blocked by #5]
10. Integrate SAP (backend) [blocked by #5]

Routes & Connections:
11. Define API routes (integration) [blocked by #6]
12. Define web routes (integration) [blocked by #7]
13. Connect frontend to backend (integration) [blocked by #11, #12]
14. Implement error handling (integration) [blocked by #13]

Testing:
15. Write model tests (backend) [blocked by #3]
16. Write service tests (backend) [blocked by #5]
17. Write API tests (integration) [blocked by #13]
```

### Collaboration Pattern

```
frontend → backend: "Need GiftCard.getQrCodeUrl() method for Filament."

backend → frontend: "Added. Returns signed S3 URL. Valid 1 hour."

integration → backend: "API returns 500 on validation errors. 
                        Should be 422."

backend → integration: "Fixed. Now returns 422 with Laravel format."

integration → all: "Routes defined. Backend: /api/v1/* prefix. 
                    Frontend: /admin/* namespace."
```

### Deliverables

```
/app/                    # Laravel application code
/resources/              # Frontend resources
/database/migrations/    # Database schema
/tests/                  # Tests
/IMPLEMENTATION_NOTES.md # Development decisions
```

### Effort Level
**MEDIUM** - Balanced reasoning for implementation.

Opus 4.6 at medium effort matches Sonnet performance while using 76% fewer output tokens.

### Duration
45-90 minutes of agent collaboration

### Checkpoint
User reviews code, tests manually, approves to proceed to Testing.

---

## Phase 3: Testing & Quality Assurance

### Objective
Comprehensive testing to ensure code quality, correctness, and reliability.

### Agent Team Structure

**3 Specialized Agents:**

1. **Unit Test Engineer** (teammate name: `unit-tester`)
   - Test Scope: `tests/Unit/`
   - Write PHPUnit tests for models
   - Test service layer methods
   - Mock external dependencies
   - Test edge cases
   - Achieve high coverage
   - Use factories

2. **Integration Tester** (teammate name: `integration-tester`)
   - Test Scope: `tests/Feature/`
   - Test API endpoints
   - Verify database operations
   - Test authentication/authorization
   - Test external API integrations (mocked)
   - Validate error handling
   - Test middleware

3. **End-to-End Tester** (teammate name: `e2e-tester`)
   - Test Scope: `tests/Feature/` (browser tests)
   - Test complete workflows
   - Verify Filament functionality
   - Test user journeys
   - Validate data consistency
   - Test edge cases
   - Create test data scenarios

### Task Breakdown Pattern

```
Testing Tasks:

Unit Testing:
1. Write model unit tests (unit-tester)
2. Write service unit tests (unit-tester) [blocked by #1]
3. Write repository tests (unit-tester) [blocked by #1]
4. Mock external APIs (unit-tester)

Integration Testing:
5. Write API endpoint tests (integration-tester)
6. Test database operations (integration-tester)
7. Test authentication flows (integration-tester)
8. Test external integrations (integration-tester)
9. Test middleware (integration-tester)

E2E Testing:
10. Create test factories (e2e-tester)
11. Test gift card creation flow (e2e-tester) [blocked by #10]
12. Test redemption flow (e2e-tester) [blocked by #10]
13. Test CFDI generation (e2e-tester) [blocked by #10]
14. Test Filament CRUD (e2e-tester) [blocked by #10]

Quality Checks:
15. Run full test suite (all)
16. Generate coverage report (unit-tester) [blocked by #15]
17. Identify gaps (all) [blocked by #16]
18. Write additional tests (all) [blocked by #17]
19. Document bugs (all)
```

### Collaboration Pattern

```
unit-tester → integration-tester: "I'm mocking FacturamaService. 
                                    Are you testing real integration?"

integration-tester → unit-tester: "Yes, testing with VCR recordings."

e2e-tester → all: "Found edge case: redeeming expired gift card?
                   No tests for this."

unit-tester → e2e-tester: "Good catch. Adding test:
                            test_cannot_redeem_expired_card()"

integration-tester → all: "Coverage at 87%. Gap in error handling.
                           Unit-tester, can you add?"
```

### Deliverables

```
/tests/
├── Unit/           # Unit tests
├── Feature/        # Integration tests
└── Browser/        # E2E tests (if using Dusk)

/coverage/          # Coverage HTML report

/TESTING_REPORT.md  # Test summary, coverage, issues
```

### Effort Level
**MEDIUM** - Testing requires careful validation.

### Duration
30-60 minutes of agent collaboration

### Checkpoint
User reviews test results, coverage report, and bug reports. Approves to proceed to Documentation.

---

## Phase 4: Documentation

### Objective
Create comprehensive, clear documentation for developers, users, and maintainers.

### Agent Team Structure

**3 Specialized Agents:**

1. **API Documentor** (teammate name: `api-docs`)
   - Scope: `/docs/api/`
   - Generate OpenAPI/Swagger specs
   - Document all endpoints
   - Provide examples
   - Document authentication
   - Explain error codes
   - Create usage guides

2. **Technical Writer** (teammate name: `tech-writer`)
   - Scope: `/docs/technical/`
   - Document architecture
   - Write setup/installation guides
   - Create deployment docs
   - Document configuration
   - Write troubleshooting guides
   - Create developer onboarding
   - Document ADRs

3. **User Guide Writer** (teammate name: `user-guide`)
   - Scope: `/docs/guides/`
   - Write admin panel guides
   - Document common workflows
   - Create tutorials
   - Write FAQ
   - Create quick start guides
   - Document business processes

### Task Breakdown Pattern

```
Documentation Tasks:

API Documentation:
1. Generate OpenAPI spec (api-docs)
2. Document authentication (api-docs) [blocked by #1]
3. Document endpoints (api-docs) [blocked by #1]
4. Add examples (api-docs) [blocked by #3]
5. Document error codes (api-docs) [blocked by #3]

Technical Documentation:
6. Document architecture (tech-writer)
7. Write installation guide (tech-writer)
8. Write deployment guide (tech-writer)
9. Document configuration (tech-writer)
10. Write troubleshooting guide (tech-writer)
11. Create ADRs (tech-writer)

User Documentation:
12. Write admin guide (user-guide)
13. Document creation workflow (user-guide) [blocked by #12]
14. Document redemption workflow (user-guide) [blocked by #12]
15. Document CFDI process (user-guide) [blocked by #12]
16. Create FAQ (user-guide)
17. Write quick start (user-guide)

Finalization:
18. Review for consistency (all)
19. Ensure consistent terminology (all) [blocked by #18]
20. Create README (tech-writer) [blocked by #1-17]
21. Generate table of contents (tech-writer) [blocked by #20]
```

### Collaboration Pattern

```
user-guide → api-docs: "Writing POS integration guide. 
                        Can you provide curl example?"

api-docs → user-guide: "Added in API docs under 'Quick Examples'."

tech-writer → all: "Let's standardize terminology:
                    - 'Gift Card' (not 'Card')
                    - 'Redemption' (not 'Usage')
                    - 'Balance' (not 'Remaining Amount')
                    Agree?"

user-guide → tech-writer: "Agreed. Updating docs."

tech-writer → user-guide: "Your workflow diagrams are great.
                           Can I link from architecture docs?"

user-guide → tech-writer: "Yes! They're in /docs/guides/workflows/"
```

### Deliverables

```
/docs/
├── api/                # API documentation
├── technical/          # Technical docs
├── guides/            # User guides
└── architecture/      # ADRs

/README.md             # Project overview
/DEPLOYMENT.md         # Deployment instructions
```

### Effort Level
**LOW** - Documentation is straightforward writing.

At low effort, Opus 4.6 is faster and more cost-effective for routine tasks.

### Duration
20-40 minutes of agent collaboration

### Checkpoint
User reviews documentation, verifies accuracy, approves to complete workflow.

---

## Inter-Phase Communication

### Feedback Loop Pattern

If Phase N discovers issues in Phase N-1 outputs:

```
❌ DON'T: Have Testing agent message Planning agent directly

✅ DO:
1. Testing phase creates: /feedback/ISSUE-001-missing-validation.md
2. Lead (user or Claude) reviews feedback
3. Decides: Re-run Planning team with specific task?
4. If approved: Planning team addresses ONLY that issue
5. Updated specs merge back
```

### Handoff Checklist

Between each phase:

```markdown
## Phase [N] → Phase [N+1] Handoff

### Deliverables Verification
- [ ] All required files created
- [ ] Documentation complete
- [ ] No TODO/FIXME comments
- [ ] All tasks marked complete

### User Approval
- [ ] Present summary to user
- [ ] Highlight key decisions
- [ ] Note any trade-offs made
- [ ] Wait for explicit approval

### Team Cleanup
- [ ] Shutdown current agent team
- [ ] Archive team conversation logs
- [ ] Clear shared task list
```

---

## Example: Complete Session

```
USER: "Build a gift card redemption system with Filament admin"

===== PHASE 1: PLANNING =====

CLAUDE (Lead): I'll create a planning team to design this system.

[Spawns Agent Team: architect, business, security]
[Agents collaborate via shared task list for 30 minutes]

CLAUDE (Lead): Planning complete. Key decisions:
- Gift cards in `gift_cards` table
- Redemption tracking in `gift_card_transactions`
- Filament resource for admin
- API endpoint for POS redemption
- Security: PIN-based, rate limiting

Review /planning/*. Proceed to development? (yes/no)

USER: yes

===== PHASE 2: DEVELOPMENT =====

CLAUDE (Lead): Starting development team.

[Spawns Agent Team: backend, frontend, integration]
[Agents collaborate, avoiding file conflicts for 1 hour]

CLAUDE (Lead): Development complete. Implemented:
- GiftCard model with relationships
- GiftCardService for business logic
- Filament resource with custom actions
- API endpoint for redemption
- Basic integration tests

Review /app/*. Proceed to testing? (yes/no)

USER: yes

===== PHASE 3: TESTING =====

CLAUDE (Lead): Starting testing team.

[Spawns Agent Team: unit-tester, integration-tester, e2e-tester]
[Agents find and report issues for 45 minutes]

CLAUDE (Lead): Testing complete. Results:
- 87% code coverage
- All tests passing
- 2 edge cases documented
- Redemption endpoint < 200ms

Review /tests/ and /TESTING_REPORT.md. Proceed to docs? (yes/no)

USER: yes

===== PHASE 4: DOCUMENTATION =====

CLAUDE (Lead): Starting documentation team.

[Spawns Agent Team: api-docs, tech-writer, user-guide]
[Agents ensure consistency for 30 minutes]

CLAUDE (Lead): Documentation complete:
- API docs with examples
- Admin user guide
- Technical architecture doc
- Deployment guide

Review /docs/ and README.md.

===== WORKFLOW COMPLETE =====

Total time: ~2.5 hours (mostly autonomous)
Total cost: ~$35 (850K tokens)
Deliverables:
✅ Complete planning documentation
✅ Tested Laravel application
✅ Filament admin interface
✅ API endpoints
✅ Comprehensive tests (87% coverage)
✅ Full documentation
```

---

## Summary

DevTeam Laravel orchestrates 4 phases of autonomous development:

1. **Planning**: Architecture, requirements, security
2. **Development**: Backend, frontend, integration
3. **Testing**: Unit, integration, E2E
4. **Documentation**: API, technical, user guides

Each phase:
- Spawns specialized agent teams
- Agents collaborate in parallel
- Produces documented deliverables
- Requires user approval before next phase

Result: Production-ready code with tests and documentation in hours, not days.
