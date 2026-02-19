---
name: devteam-laravel
description: >
  Spawn a complete AI development team using Claude Opus 4.6 Agent Teams specifically for 
  Laravel/Filament projects. Executes 4-phase workflow: Planning (architecture, requirements) → 
  Development (backend, frontend, integration) → Testing (unit, integration, E2E) → Documentation 
  (API, technical, user guides). Each phase uses specialized agents collaborating in parallel via 
  shared task lists and peer-to-peer messaging. Deeply optimized for Laravel 12, Filament 3, React, 
  AWS, Mexican CFDI compliance (Facturama), and business integrations (SAP Business One, REVO). 
  Includes Laravel-specific patterns: Repository pattern, Service layer, Filament resources, 
  API resources, Jobs, Events. Cost optimization via effort level tuning, file ownership to prevent 
  conflicts, and user approval checkpoints between phases. Best for Laravel features requiring 
  multiple components, complex integrations, comprehensive testing, or full documentation.
---

# DevTeam Laravel - AI Development Team for Laravel/Filament

Transform feature requests into production-ready Laravel applications using autonomous agent teams.

## Quick Start

```bash
# Prerequisites
export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
export ANTHROPIC_MODEL="claude-opus-4-6"

# Start Claude Code
claude --model claude-opus-4-6
```

Then simply describe what you want to build:

```
"Build a gift card redemption system with Filament admin and CFDI invoicing"
```

DevTeam Laravel will:
1. ✅ Spawn specialized agent teams for each development phase
2. ✅ Generate specs, code, tests, and documentation autonomously
3. ✅ Present results at checkpoints for your approval
4. ✅ Deliver production-ready code in hours, not days

---

## What This Skill Does

DevTeam Laravel orchestrates **autonomous AI development teams** for Laravel projects using Claude Opus 4.6's native Agent Teams feature.

### Core Capabilities

**Multi-Phase Workflow:**
- **Phase 1: Planning** - Architecture, requirements, security
- **Phase 2: Development** - Backend, frontend, integration
- **Phase 3: Testing** - Unit, integration, end-to-end
- **Phase 4: Documentation** - API, technical, user guides

**Agent Collaboration:**
- Agents work in parallel within each phase
- Direct peer-to-peer communication via shared task lists
- File ownership to prevent merge conflicts
- Autonomous coordination with minimal human intervention

**Quality Assurance:**
- Multi-agent review and debate
- Comprehensive testing (unit, integration, E2E)
- Code coverage tracking
- Full documentation generation

**Cost Optimization:**
- Effort level tuning per phase (HIGH → MEDIUM → LOW)
- Smart model usage (Opus 4.6 with adaptive thinking)
- Efficient token usage through clear file ownership

---

## When to Use DevTeam

✅ **Perfect for:**
- New feature development with multiple components
- Complete system builds (e.g., "Build a CRM with reporting")
- Large refactoring spanning 5+ files
- Complex integrations (SAP, Facturama, payment gateways)
- Projects requiring comprehensive testing
- Features needing full documentation

❌ **Not suitable for:**
- Single-file changes or quick bug fixes
- Exploratory prototyping
- Simple CRUD operations
- Minor documentation updates

---

## Core Workflow

### High-Level Flow

```
USER REQUEST
    ↓
PHASE 1: Planning Team (Architect, Business, Security)
    → Specs, Architecture, Data Model, Security Requirements
    ↓
[USER APPROVAL CHECKPOINT]
    ↓
PHASE 2: Development Team (Backend, Frontend, Integration)
    → Laravel Code, Filament Resources, Tests, Migrations
    ↓
[USER APPROVAL CHECKPOINT]
    ↓
PHASE 3: Testing Team (Unit, Integration, E2E)
    → Test Suite, Coverage Report, Bug Documentation
    ↓
[USER APPROVAL CHECKPOINT]
    ↓
PHASE 4: Documentation Team (API, Technical, User Guide)
    → API Docs, Technical Guides, User Documentation
    ↓
DELIVERY
```

### Detailed Workflow

For complete workflow details including:
- Agent team structures per phase
- Task breakdown patterns
- File ownership strategies
- Collaboration patterns
- Deliverables per phase

**See:** `references/workflow.md`

---

## Architecture

### Agent Team Structure

Each phase spawns a team of 2-3 specialized agents:

```
┌─────────────────────────────────────────┐
│ PHASE 1: PLANNING                       │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐│
│ │Architect │←│ Business │←│ Security ││
│ └──────────┘ └──────────┘ └──────────┘│
│ Collaborate via shared task list       │
└─────────────────────────────────────────┘
         ↓ (User Approval)
┌─────────────────────────────────────────┐
│ PHASE 2: DEVELOPMENT                    │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐│
│ │ Backend  │←│ Frontend │←│Integration││
│ └──────────┘ └──────────┘ └──────────┘│
│ File ownership prevents conflicts      │
└─────────────────────────────────────────┘
         ↓ (User Approval)
[... continues through Testing and Documentation]
```

### Key Principles

1. **Intra-Phase Parallelism**: Agents collaborate within phases
2. **Inter-Phase Sequence**: Phases execute in order with clean handoffs
3. **Clear Contracts**: Each phase produces documented outputs
4. **User Control**: Approval checkpoints between phases
5. **File Ownership**: Strict ownership prevents merge conflicts

**See:** `references/architecture.md` for detailed architecture patterns

---

## Laravel/Filament Optimizations

DevTeam is specifically optimized for Laravel ecosystem development:

### Technology Stack
- Laravel 12.x
- Filament 3.x (Admin Panels)
- Livewire 3.x
- React 18 (Customer Portals)
- Tailwind CSS 3.x
- MySQL 8 / AWS Aurora

### Common Patterns
- Repository pattern for data access
- Service layer for business logic
- Filament resources for admin CRUD
- API resources for JSON responses
- Jobs for async processing
- Events for cross-module communication

### Integrations
- **CFDI 4.0**: Facturama API for Mexican electronic invoicing
- **SAP Business One**: Service Layer integration
- **AWS Services**: S3, SES, Lambda, Aurora, SQS
- **Payment Gateways**: Stripe, PayPal, etc.

**See:** `references/laravel-patterns.md` for detailed implementation patterns

---

## Project Setup

### Required: CLAUDE.md

Create a `CLAUDE.md` file in your Laravel project root with project context:

```markdown
# [Project Name] - Context for Claude

## Tech Stack
- Laravel 12.x
- Filament 3.x
- MySQL 8.x / AWS Aurora
- Redis for caching

## Architecture
- Repository pattern
- Service layer for business logic
- Filament for admin panels

## Integrations
- Facturama API (CFDI 4.0)
- SAP Business One
- AWS Services (S3, SES, Lambda)

## Coding Standards
- PSR-12 compliance
- Use Spatie packages
- Follow Filament conventions

## File Structure
- Models: app/Models/
- Services: app/Services/
- Filament: app/Filament/Resources/
- API: app/Http/Controllers/Api/

## Testing
- PHPUnit for unit tests
- Pest for feature tests
- Minimum 80% coverage
```

**See:** `examples/CLAUDE.md` for a complete example

---

## Usage Examples

### Example 1: Gift Card System

```
User: "Build a gift card management system with Filament admin,
       redemption API, and CFDI invoice generation via Facturama"

DevTeam:
- Planning: Designs architecture, data model, API contracts
- Development: Implements models, services, Filament resources, API
- Testing: Writes unit, integration, and E2E tests (87% coverage)
- Documentation: API docs, admin guide, deployment instructions

Delivery: 3-4 hours, ~$42 cost, production-ready code
```

### Example 2: Travel Expense System

```
User: "Modernize travel expense system from V3.1 to V4.0.
       Keep CFDI validation, add React frontend"

DevTeam:
- Planning: Migration strategy, new architecture, data model changes
- Development: Refactors backend, builds React frontend, maintains CFDI
- Testing: Regression tests + new feature tests
- Documentation: Migration guide, new API docs, React component docs

Delivery: 6-8 hours, ~$85 cost, migration-ready with rollback plan
```

### Example 3: SAP Integration

```
User: "Create SAP Business One integration for syncing
       invoices and inventory"

DevTeam:
- Planning: SAP Service Layer endpoints, data mapping, error handling
- Development: SapService class, sync jobs, Filament dashboard
- Testing: Integration tests with SAP sandbox, error scenarios
- Documentation: SAP setup guide, troubleshooting, API reference

Delivery: 4-5 hours, ~$55 cost, tested integration with monitoring
```

**See:** `examples/` directory for more complete examples

---

## Cost Optimization

### Effort Level Strategy

| Phase | Effort | Reasoning | Token Savings |
|-------|--------|-----------|---------------|
| Planning | HIGH | Complex decisions | None (required) |
| Development | MEDIUM | Balanced | 76% vs HIGH |
| Testing | MEDIUM | Validation | 76% vs HIGH |
| Documentation | LOW | Routine writing | 85% vs HIGH |

### Team Size Options

**Standard (Recommended):**
- 3 agents per phase = 12 total agents
- Best quality, comprehensive coverage

**Budget (For smaller projects):**
- 2 agents per phase = 8 total agents
- 33% token savings, still good quality

### Cost Estimates

| Project Size | Phases | Tokens | Cost | Time |
|-------------|--------|--------|------|------|
| Small Feature | 2-3 | 400K | $20 | 1-2 hrs |
| Medium Feature | 4 | 850K | $42 | 3-4 hrs |
| Large System | 4+ iterations | 3M | $150 | 8-12 hrs |

**See:** `references/cost-optimization.md` for detailed strategies

---

## Troubleshooting

### Common Issues

**Agent Teams Not Starting:**
```bash
# Verify environment variable
echo $CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS
# Should output: 1

# Set if missing
export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
```

**Wrong Model Being Used:**
```bash
# Check current model
/status

# Switch to Opus 4.6
/model claude-opus-4-6
```

**High Token Usage:**
- Reduce team size (2 agents instead of 3)
- Lower effort levels for non-critical phases
- Better task definition (more specific = less exploration)

**File Conflicts:**
- Ensure clear file ownership in task prompts
- Use task dependencies to sequence work
- Have agents coordinate via messages before editing shared files

**See:** `references/troubleshooting.md` for complete troubleshooting guide

---

## Best Practices

### 1. Invest in Planning
- Provide clear, detailed requirements upfront
- Answer agent questions thoroughly
- Review planning documents carefully
- Good planning = faster development = lower cost

### 2. Use CLAUDE.md Effectively
- Document project-specific patterns
- Include anti-patterns to avoid
- List common gotchas
- Update after each project

### 3. Maintain File Ownership
- Each agent owns specific directories
- Agents coordinate before touching shared files
- Use task dependencies to sequence work

### 4. Approve Thoughtfully
- Review ALL deliverables at checkpoints
- Test manually if possible
- Request changes before approving
- Easier to fix in current phase than backtrack

### 5. Start Small, Scale Up
- First project: Single feature
- Learn the patterns
- Then tackle larger systems

**See:** `references/best-practices.md` for comprehensive best practices

---

## Advanced Patterns

### Custom Agent Roles

Request specialized agents for specific needs:

```
"For development, spawn:
- Backend Engineer (Laravel)
- Frontend Engineer (React + Filament)
- Database Specialist (MySQL optimization)
- CFDI Integration Specialist (Facturama expert)"
```

### Adding Custom Phases

Insert phases between standard ones:

```
Phase 2.5: Security Audit
- Spawn 2-3 security specialists
- Audit code for vulnerabilities
- Run OWASP checks
- Document findings
```

### Iterative Development

Break large features into iterations:

```
Iteration 1: MVP (full workflow)
Iteration 2: Enhanced Features (full workflow)
Iteration 3: Polish & Optimization (Development + Testing only)
```

**See:** `references/advanced-patterns.md`

---

## References

Complete documentation is organized in the `references/` directory:

- `workflow.md` - Detailed workflow per phase
- `architecture.md` - Agent team structures and patterns
- `laravel-patterns.md` - Laravel/Filament specific implementations
- `cost-optimization.md` - Token usage and cost strategies
- `troubleshooting.md` - Common issues and solutions
- `best-practices.md` - Proven patterns and anti-patterns
- `advanced-patterns.md` - Custom configurations

## Scripts

Helper scripts in `scripts/` directory:

- `init_project.py` - Initialize new Laravel project for devteam
- `validate_claude_md.py` - Validate CLAUDE.md format
- `estimate_cost.py` - Estimate token usage for project

## Examples

Real-world examples in `examples/` directory:

- Gift card system
- Travel expense management
- SAP Business One integration
- CFDI invoice generation

---

## Limitations

Current limitations of Agent Teams (Opus 4.6):

1. **Model Selection**: All teammates use same model (no per-agent model selection)
2. **No Nested Teams**: Teammates cannot spawn their own teams
3. **Session Resumption**: Cannot resume interrupted team sessions
4. **Split Panes**: Requires tmux or iTerm2 (not all terminals)

**Workarounds documented in:** `references/limitations-workarounds.md`

---

## Version History

- **v1.0.0** (2026-02-07): Initial release
  - Claude Opus 4.6 Agent Teams support
  - 4-phase workflow (Planning, Development, Testing, Documentation)
  - Laravel/Filament optimizations
  - CFDI compliance patterns
  - Cost optimization strategies

---

## Support

For issues, questions, or improvements:

1. Check `references/troubleshooting.md`
2. Review `examples/` for similar use cases
3. Update your `CLAUDE.md` with project-specific context
4. Provide feedback to improve this skill

---

## Quick Reference Card

```
┌─────────────────────────────────────────────────────┐
│ DevTeam Quick Reference                             │
├─────────────────────────────────────────────────────┤
│ SETUP:                                              │
│   export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1    │
│   export ANTHROPIC_MODEL="claude-opus-4-6"         │
│                                                     │
│ USAGE:                                              │
│   "Build [feature] with [requirements]"            │
│                                                     │
│ PHASES:                                             │
│   1. Planning   → specs, architecture              │
│   2. Development → code, tests, migrations         │
│   3. Testing    → test suite, coverage             │
│   4. Documentation → API, technical, user docs     │
│                                                     │
│ CHECKPOINTS:                                        │
│   Review → Approve → Next Phase                    │
│                                                     │
│ COST (Medium Feature):                              │
│   ~850K tokens = ~$42 = 3-4 hours                  │
│                                                     │
│ FILES:                                              │
│   CLAUDE.md - Project context (REQUIRED)           │
│   references/ - Full documentation                  │
│   examples/ - Real-world examples                   │
└─────────────────────────────────────────────────────┘
```

---

**Ready to build? Just describe what you want, and DevTeam Laravel will orchestrate the entire development process autonomously.**

🚀 **Happy autonomous coding!**
