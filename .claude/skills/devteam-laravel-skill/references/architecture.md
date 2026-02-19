# Architecture Reference

Deep dive into DevTeam's architectural patterns and decisions.

---

## Core Architecture

### Multi-Agent System Design

DevTeam implements a **hierarchical multi-agent architecture** with clear separation of concerns:

```
┌─────────────────────────────────────────────┐
│          User (Product Owner)               │
└──────────────────┬──────────────────────────┘
                   │
                   ↓
┌─────────────────────────────────────────────┐
│       Lead Agent (Claude/Orchestrator)      │
│  - Interprets user intent                   │
│  - Spawns phase-specific teams              │
│  - Manages checkpoints                      │
│  - Synthesizes results                      │
└──────────────────┬──────────────────────────┘
                   │
       ┌───────────┴───────────┐
       ↓                       ↓
┌──────────────┐        ┌──────────────┐
│   Phase N    │   ...  │   Phase N+1  │
│  Agent Team  │        │  Agent Team  │
└──────┬───────┘        └──────┬───────┘
       │                       │
   ┌───┴───┐               ┌───┴───┐
   ↓       ↓               ↓       ↓
[Agent] [Agent]         [Agent] [Agent]
```

---

## Phase Architecture

### Sequential Phase Execution

Phases execute **sequentially** with hard boundaries:

```
Planning → Development → Testing → Documentation
   ↓           ↓            ↓           ↓
 specs       code        tests        docs
```

**Why sequential, not parallel?**
- Dependencies: Development needs specs, Testing needs code
- Clean contracts: Each phase produces defined outputs
- Clear approvals: User reviews one phase at a time
- Resource efficiency: Focus compute on current phase

---

### Intra-Phase Parallelism

Within each phase, agents work in **parallel**:

```
Phase 2: Development
┌────────────────────────────────────┐
│  Backend ←→ Frontend ←→ Integration │ (Simultaneous work)
└────────────────────────────────────┘
        ↓ Shared Task List ↓
    [Task 1] [Task 2] [Task 3]
```

**Coordination mechanisms**:
1. **Shared Task List**: Central task queue with dependencies
2. **Peer Messages**: Direct agent-to-agent communication
3. **File Ownership**: Clear boundaries prevent conflicts

---

## Agent Communication Patterns

### 1. Task List Communication (Async)

```
Agent A creates task:
  Task 5: "Create GiftCard model"
  Owner: Backend
  Dependencies: [Task 2, Task 3]
  Status: Pending

Agent B claims task:
  Task 5: Status → In Progress

Agent B completes task:
  Task 5: Status → Complete
  Output: app/Models/GiftCard.php
```

**Advantages**:
- Clear task ownership
- Dependency tracking
- Progress visibility
- Async coordination

---

### 2. Peer Messages (Sync)

```
Frontend → Backend: "Need GiftCard.getQrCodeUrl() method"
Backend → Frontend: "Added. Returns signed S3 URL, valid 1 hour"
```

**When to use**:
- Questions about implementation
- Coordination on shared concerns
- Clarifications on interfaces
- Debate on trade-offs

---

### 3. Lead Escalation

```
Agent → Lead: "Backend and Frontend disagree on API format"
Lead → User: "Team needs decision: REST or GraphQL?"
User → Lead: "Use REST"
Lead → Agents: "Decision: REST. Backend, define OpenAPI spec."
```

**When to escalate**:
- Business decision needed
- Technical tie-breaker required
- Blocking issue
- Resource constraint

---

## File Ownership Strategy

### Why File Ownership?

**Problem**: Multiple agents editing same file = conflicts

**Solution**: Clear file ownership per agent role

### Ownership Matrix

| Directory | Owner | Rationale |
|-----------|-------|-----------|
| `app/Models/` | Backend | Data structure definitions |
| `app/Services/` | Backend | Business logic |
| `app/Repositories/` | Backend | Data access |
| `database/migrations/` | Backend | Schema changes |
| `tests/Unit/` | Backend | Unit testing their code |
| `app/Filament/` | Frontend | Admin interface |
| `resources/views/` | Frontend | Blade templates |
| `resources/js/` | Frontend | JavaScript/React |
| `routes/` | Integration | Routing definitions |
| `app/Http/Middleware/` | Integration | Request processing |
| `tests/Feature/` | Integration | End-to-end tests |

### Shared Files (Coordination Required)

```
app/Providers/
├── AppServiceProvider.php      # Shared
├── EventServiceProvider.php    # Shared
└── RouteServiceProvider.php    # Integration owns

composer.json                    # Shared
package.json                     # Frontend owns
```

**Coordination protocol**:
```
Agent A: "I need to update composer.json to add package X"
Agent B: "Go ahead, I'm not touching it now"
Agent A: [Updates composer.json]
Agent A: "composer.json updated with X"
```

---

## Task Dependency System

### Dependency Graph

```
Task 1: Create migration
  ↓
Task 2: Create model [BLOCKED BY Task 1]
  ↓
Task 3: Create service [BLOCKED BY Task 2]
  ↓ 
Task 4: Create Filament resource [BLOCKED BY Task 2]
```

### Dependency Types

**1. Sequential (A → B)**
```
Task 2 depends on Task 1 completing
Task 2 cannot start until Task 1 is done
```

**2. Join (A,B → C)**
```
Task 5 depends on Tasks 3 AND 4
Task 5 waits for both to complete
```

**3. Optional (A → ?B)**
```
Task 7 prefers Task 6 done first, but can proceed
Used for optimizations, not hard requirements
```

---

## Effort Level Architecture

### Opus 4.6 Adaptive Thinking

DevTeam uses Opus 4.6's **adaptive thinking** feature for cost optimization:

```
┌──────────────────────────────────────────┐
│         Effort Level Spectrum            │
├──────────────────────────────────────────┤
│ LOW    │ MEDIUM │ HIGH                   │
│ ────────────────────────────────────────│
│ Routine│Balanced│Complex                │
│ Fast   │Standard│Deep reasoning         │
│ Cheap  │Optimal │Expensive but thorough │
└──────────────────────────────────────────┘
```

### Effort Level Strategy

| Phase | Effort | Why | Token Ratio |
|-------|--------|-----|-------------|
| Planning | HIGH | Critical decisions, architecture | 100% (baseline) |
| Development | MEDIUM | Balanced coding | 24% of HIGH |
| Testing | MEDIUM | Thorough validation | 24% of HIGH |
| Documentation | LOW | Routine writing | 15% of HIGH |

**Example token usage** (medium project):
```
Planning:     150K tokens (HIGH)
Development:  400K tokens (MEDIUM) - Would be 1.67M at HIGH
Testing:      200K tokens (MEDIUM) - Would be 833K at HIGH  
Documentation: 100K tokens (LOW)   - Would be 667K at HIGH

Total: 850K tokens
If all HIGH: 3.17M tokens
Savings: 73% reduction
```

---

## State Management

### Phase State

Each phase maintains:

```python
PhaseState = {
    "phase_name": "development",
    "status": "in_progress",  # pending | in_progress | reviewing | complete
    "agents": [
        {"name": "backend", "status": "working", "current_task": 5},
        {"name": "frontend", "status": "working", "current_task": 7},
        {"name": "integration", "status": "idle", "current_task": None},
    ],
    "tasks": [
        {"id": 1, "status": "complete", "owner": "backend"},
        {"id": 2, "status": "complete", "owner": "backend"},
        {"id": 5, "status": "in_progress", "owner": "backend"},
        {"id": 7, "status": "in_progress", "owner": "frontend"},
    ],
    "deliverables": {
        "expected": ["app/Models/", "app/Services/", "app/Filament/"],
        "completed": ["app/Models/", "app/Services/"],
        "missing": ["app/Filament/"],
    }
}
```

### Workflow State

```python
WorkflowState = {
    "project_name": "Gift Card System",
    "current_phase": "development",
    "phases_completed": ["planning"],
    "phases_remaining": ["testing", "documentation"],
    "user_approvals": {
        "planning": {"approved": True, "timestamp": "2026-02-07T10:30:00"},
        "development": {"approved": False, "timestamp": None},
    },
    "cost_tracking": {
        "total_tokens": 550000,
        "by_phase": {
            "planning": 150000,
            "development": 400000,
        }
    }
}
```

---

## Checkpoint Architecture

### Checkpoint Flow

```
┌─────────────────────────────────────────┐
│       Phase N Completes                 │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│  Lead Synthesizes Results               │
│  - Aggregate deliverables               │
│  - Check completeness                   │
│  - Prepare summary                      │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│  Present to User                        │
│  - Show deliverables                    │
│  - Highlight decisions                  │
│  - Note trade-offs                      │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│  User Decision                          │
│  ┌──────────┬──────────┬──────────┐   │
│  │ Approve  │  Revise  │  Reject  │   │
│  └────┬─────┴────┬─────┴────┬─────┘   │
└───────┼──────────┼──────────┼─────────┘
        ↓          ↓          ↓
   Next Phase  Same Phase   Stop
                (new team)
```

### Checkpoint Data

```python
Checkpoint = {
    "phase": "planning",
    "status": "awaiting_approval",
    "deliverables": {
        "planning/REQUIREMENTS.md": {
            "size": "4.2 KB",
            "sections": ["Functional", "Non-Functional", "Constraints"],
        },
        "planning/ARCHITECTURE.md": {
            "size": "6.8 KB",
            "decisions": ["Repository pattern", "Service layer", "Filament admin"],
        },
    },
    "key_decisions": [
        "Using auto-increment IDs (not UUIDs)",
        "CFDI generation via async job",
        "Rate limiting: 10 req/min per card",
    ],
    "trade_offs": [
        "Chose simplicity over scalability (can refactor later)",
        "Manual testing for CFDI (no sandbox available)",
    ],
    "questions_for_user": [
        "Should gift cards be transferable between users?",
    ],
}
```

---

## Feedback Loop Architecture

### Inter-Phase Feedback

```
┌──────────────────────────────────────────┐
│  Testing Phase discovers issue in specs  │
└────────────────┬─────────────────────────┘
                 ↓
┌──────────────────────────────────────────┐
│  Create /feedback/ISSUE-001.md          │
│  Content:                                │
│  - What was discovered                   │
│  - Which spec needs update               │
│  - Proposed solution                     │
└────────────────┬─────────────────────────┘
                 ↓
┌──────────────────────────────────────────┐
│  Lead reviews feedback with User         │
└────────────────┬─────────────────────────┘
                 ↓
         ┌───────┴────────┐
         ↓                ↓
    Fix in        Re-run Planning
  current phase   (targeted fix only)
```

**Feedback file format**:

```markdown
# ISSUE-001: Missing Validation Specification

## Discovered By
Testing Phase - Integration Tester

## Current State
SECURITY.md does not specify validation rules for gift card codes.

## Impact
Cannot write comprehensive tests without knowing valid formats.

## Proposed Solution
Add to SECURITY.md:
- Code format: GC-YYYY-XXXXXXXX (e.g., GC-2026-A1B2C3D4)
- Allowed characters: A-Z, 0-9
- Length: Always 16 characters including dashes

## Priority
High - blocks testing progress
```

---

## Scalability Considerations

### Current Limitations

1. **No nested teams**: Teammates cannot spawn sub-teams
2. **Context per teammate**: Each teammate has own 200K token window
3. **Single model**: All teammates use same model (Opus 4.6)
4. **Session-bound**: Cannot resume after disconnect

### Scale Strategies

**For larger projects**:

1. **Phase splitting**:
```
Instead of: 1 Development phase
Use:        Development Phase 1 (Backend)
            Development Phase 2 (Frontend)
            Development Phase 3 (Integration)
```

2. **Iterative delivery**:
```
Iteration 1: MVP with core features
Iteration 2: Additional features
Iteration 3: Polish and optimization
```

3. **Module-based**:
```
Module 1: User management (full workflow)
Module 2: Product catalog (full workflow)
Module 3: Order processing (full workflow)
```

---

## Comparison with Alternative Architectures

### DevTeam vs Single Agent

| Aspect | Single Agent | DevTeam |
|--------|--------------|---------|
| Perspective | One viewpoint | Multiple viewpoints |
| Specialization | Generalist | Specialists per phase |
| Parallelization | Sequential | Parallel within phase |
| Quality | Good | Better (peer review) |
| Cost | Lower | Higher but more value |
| Complexity | Low | Medium |

### DevTeam vs Fully Parallel

| Aspect | Fully Parallel | DevTeam |
|--------|----------------|---------|
| All phases at once | Yes | No (sequential) |
| Coordination overhead | Very high | Medium |
| Dependency management | Complex | Simple |
| User checkpoints | Difficult | Natural |
| Feedback loops | Messy | Clean |

**DevTeam sweet spot**: Sequential phases, parallel agents within phases

---

## Future Architecture Enhancements

### Potential Improvements

1. **Persistent Knowledge Base**
   - Agents access project history across sessions
   - Learn from past decisions
   - Avoid repeating mistakes

2. **Dynamic Team Sizing**
   - Automatically scale team size based on complexity
   - Spawn additional specialists when needed

3. **Cross-Phase Agents**
   - Security specialist available across all phases
   - Performance specialist reviews critical paths

4. **Automated Testing in Loop**
   - Tests run after each development task
   - Immediate feedback to developers
   - TDD workflow

---

## Summary

DevTeam architecture principles:

1. ✅ **Hierarchical**: Lead → Phase Teams → Agents
2. ✅ **Sequential phases**: Clear dependencies and checkpoints
3. ✅ **Parallel agents**: Efficiency within phases
4. ✅ **Clear ownership**: Prevent conflicts
5. ✅ **Task dependencies**: Correct execution order
6. ✅ **Effort optimization**: Match effort to complexity
7. ✅ **User control**: Checkpoints and approvals
8. ✅ **Clean feedback**: Issues logged and resolved

**Result**: Scalable, efficient, high-quality autonomous development

---

**Last Updated**: 2026-02-07
