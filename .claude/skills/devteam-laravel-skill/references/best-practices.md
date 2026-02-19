# Best Practices Guide

Proven patterns for successful DevTeam workflows.

---

## Before Starting a Project

### 1. Invest in Planning

**Why**: Good planning = faster development = lower total cost

**Do**:
- ✅ Provide clear, detailed requirements upfront
- ✅ Answer agent questions thoroughly
- ✅ Review planning documents carefully
- ✅ Only approve when specs are complete and clear

**Don't**:
- ❌ Rush through planning to "get to coding faster"
- ❌ Provide vague requirements like "build something nice"
- ❌ Approve planning with placeholders like "[TODO]"

**Example**:
```
Bad: "Build an e-commerce site"

Good: "Build a product catalog system with:
- Product model: name, description, price, stock, category
- Filament admin for CRUD operations
- Public API for product listing
- Image upload to S3
- Stock tracking with low-stock alerts"
```

---

### 2. Create Comprehensive CLAUDE.md

**Why**: Better context = fewer tokens wasted on exploration

**Do**:
```bash
# Initialize project with script
python devteam-skill/scripts/init_project.py /path/to/project

# Then customize with:
- Exact tech stack versions
- Project-specific patterns
- Integration details (Facturama, SAP, etc.)
- Common gotchas discovered
- Anti-patterns to avoid
```

**Template Sections**:
```markdown
# [Project Name]

## Tech Stack
- Exact versions
- Dependencies

## Architecture
- Patterns used
- Service structure

## Integrations
- APIs with details
- Authentication methods

## Coding Standards
- PSR-12 compliance
- Type hints required
- Specific conventions

## What NOT to Do
❌ Anti-patterns
❌ Known issues to avoid

## Common Gotchas
- Timezone handling
- API rate limits
- Session timeouts
```

**Validate**:
```bash
python devteam-skill/scripts/validate_claude_md.py
```

---

### 3. Estimate Costs Upfront

**Why**: Avoid surprise bills, plan budget

```bash
python devteam-skill/scripts/estimate_cost.py

# Interactive mode asks:
# - Project size (small/medium/large)
# - Team size per phase (2-3 agents)
# - Phases to skip (if any)
```

**Use estimates to**:
- Get approval from stakeholders
- Choose appropriate project size
- Decide if manual coding is better for very small tasks

---

## During Planning Phase

### 4. Let Agents Debate

**Why**: Better decisions come from multiple perspectives

**Do**:
- ✅ Let agents challenge each other
- ✅ Let them debate trade-offs
- ✅ Let them coordinate autonomously

**Don't**:
- ❌ Micromanage agent conversations
- ❌ Interrupt debates prematurely
- ❌ Force specific technical decisions without agent input

**When to Intervene**:
- Agents are clearly stuck or going in circles
- Business requirement needs clarification
- Technical constraint they don't know about

**Example Good Debate**:
```
Architect: "Should we use UUID or auto-increment for IDs?"
Business: "From my requirements, we need simple sequential IDs."
Security: "Auto-increment is fine, no security issue here."
Architect: "Agreed, using auto-increment."
```

---

### 5. Answer Questions Promptly

**Why**: Agents blocked = wasted time and tokens

**When agents ask questions**:
```
Agent: "Should gift cards be refundable?"
```

**Respond quickly and clearly**:
```
User: "No, gift cards are non-refundable once activated.
       Add this to business rules."
```

**Don't**:
```
User: "Um, I'm not sure... maybe? Let me think about it..."
(Agents wait, burning tokens)
```

---

## During Development Phase

### 6. Maintain File Ownership Discipline

**Why**: Prevents merge conflicts and overwrites

**Define ownership upfront**:
```
"For development phase, file ownership:

Backend owns:
- app/Models/
- app/Services/
- app/Repositories/
- database/migrations/
- tests/Unit/

Frontend owns:
- app/Filament/Resources/
- resources/views/
- resources/js/

Integration owns:
- routes/
- app/Http/Middleware/
- tests/Feature/

Shared (coordinate first):
- app/Providers/
- composer.json"
```

**Agents should**:
- Only edit files they own
- Message others before touching shared files
- Use task dependencies to sequence work

---

### 7. Use Task Dependencies

**Why**: Agents work in correct order, avoid wasted effort

**Example**:
```
Task 1: Create database migrations (backend)
Task 2: Create models (backend) [BLOCKED BY Task 1]
Task 3: Create Filament resource (frontend) [BLOCKED BY Task 2]
Task 4: Create API routes (integration) [BLOCKED BY Task 2]
```

**Without dependencies**:
- Frontend starts before model exists → errors
- Integration starts before routes defined → conflicts

**With dependencies**:
- Work flows naturally
- No wasted effort
- Clean handoffs

---

## At Checkpoints

### 8. Review Thoroughly Before Approving

**Why**: Easier to fix in current phase than backtrack later

**At each checkpoint**:

1. **Review ALL deliverables**:
```bash
# Planning checkpoint
ls planning/
# Should see all required files

# Development checkpoint  
ls app/Models/ app/Services/ app/Filament/
# Verify structure matches plan
```

2. **Test manually if possible**:
```bash
# Development checkpoint
php artisan migrate
php artisan db:seed
# Visit /admin and test CRUD
```

3. **Ask questions**:
```
"Why did you use a Job for this instead of direct execution?"
"I don't see validation for X. Was this intentional?"
```

4. **Request changes**:
```
"Before proceeding, please:
- Add validation for email format
- Include error handling for API timeouts
- Update ARCHITECTURE.md with chosen approach"
```

5. **Only approve when satisfied**:
```
User: "Planning looks good. Proceed to development."
(Not: "Um, okay I guess, let's see what happens...")
```

---

### 9. Provide Constructive Feedback

**Why**: Agents learn from feedback, improve next phase

**Good feedback**:
```
"The data model is solid, but SECURITY.md is missing
details on rate limiting. Please add:
- Rate limit: 10 requests/min per card
- Lockout after 5 failed PIN attempts
- IP-based rate limiting"
```

**Bad feedback**:
```
"This doesn't look right."
(What doesn't look right? What should change?)
```

---

## During Testing Phase

### 10. Specify Coverage Requirements

**Why**: Prevents weak test suites

**Upfront**:
```
"For testing phase, requirements:
- Minimum 85% code coverage
- Test all edge cases:
  * Expired gift cards
  * Insufficient balance
  * Invalid PINs
  * Network failures in Facturama calls
- Mock external APIs (don't hit real Facturama in tests)"
```

**Review TESTING_REPORT.md**:
```
✅ Coverage: 87%
✅ All edge cases covered
✅ External APIs mocked
✅ Performance: All endpoints < 200ms
```

---

### 11. Don't Skip Testing

**Why**: Bugs found now are cheaper than bugs in production

**Even for "simple" features**:
- Unit tests catch logic errors
- Integration tests catch connection issues
- E2E tests catch workflow problems

**Only skip if**:
- Extreme time pressure (document as tech debt)
- Prototype/throwaway code
- Very simple view-only pages

---

## General Workflow

### 12. Start Small, Scale Up

**Why**: Learn patterns before tackling complex systems

**First DevTeam project**:
```
Don't: "Build a complete ERP system"
Do: "Build a single Filament resource with CRUD"
```

**After learning patterns**:
```
Then: "Build multi-module system with integrations"
```

**Progression**:
1. Simple CRUD (1-2 hours)
2. CRUD + API (2-3 hours)
3. CRUD + API + Integration (3-4 hours)
4. Complex multi-module system (8-12 hours)

---

### 13. Track Costs and Iterate

**Why**: Learn what works, optimize future projects

**After each project**:
```markdown
## Cost Analysis

Project: Gift Card System
Duration: 3.5 hours
Tokens: 820K
Cost: $41

Breakdown:
- Planning: 140K tokens ($7) - 30 min
- Development: 420K tokens ($21) - 90 min
- Testing: 180K tokens ($9) - 45 min
- Documentation: 80K tokens ($4) - 25 min

Learnings:
- CLAUDE.md reduced planning time by ~50%
- Clear file ownership prevented conflicts
- Specific test requirements improved coverage

Next time:
- Add CFDI integration patterns to CLAUDE.md
- Use 2 agents for documentation (3 was overkill)
```

---

### 14. Update CLAUDE.md After Each Project

**Why**: Accumulate project-specific knowledge

```markdown
## Lessons Learned

### 2026-02-07: Gift Card System
- Facturama: Use VCR recordings in tests to avoid rate limits
- QR codes: Store as S3 URLs, not binary in DB
- Filament: Custom actions better than separate pages

### 2026-02-05: Travel Expense System  
- CFDI validation: Do async via job, not in controller
- File uploads: Use S3 pre-signed URLs
- Filament: Use relation managers for has-many
```

**Each lesson saves tokens in future projects**

---

## Cost Optimization

### 15. Use Effort Levels Strategically

**Why**: Match effort to task complexity

```
Planning:      HIGH   (critical foundation)
Development:   MEDIUM (balanced approach)
Testing:       MEDIUM (thorough validation)
Documentation: LOW    (routine writing)
```

**Adjust in-session**:
```bash
/model opus
# Use arrow keys to adjust effort level
```

**Token savings**:
- MEDIUM vs HIGH: ~76% fewer output tokens
- LOW vs HIGH: ~85% fewer output tokens

---

### 16. Right-Size Team Per Phase

**Why**: More agents = more tokens, not always better

**Standard (3 agents)**:
- Best for complex features
- Multiple perspectives valuable
- Worth the extra cost

**Budget (2 agents)**:
- Good for simpler features  
- 33% token savings
- Still maintains quality

**When to use 2 agents**:
```
Planning: Architect + Business (skip Security for simple CRUD)
Development: Fullstack + Integration (combine Backend + Frontend)
Testing: Unit+Integration combined + E2E
Documentation: Technical + User (skip API docs if no API)
```

---

### 17. Skip Phases When Appropriate

**Why**: Don't pay for what you don't need

**Can skip Documentation if**:
- Internal tool with small team
- Tight deadline, can document later
- Prototype/POC

**Can skip Testing if**:
- Very simple CRUD
- Heavy time constraint  
- Manual testing preferred

**Never skip Planning**:
- Saves time and cost downstream
- Prevents rework
- Required for quality

---

## Team Management

### 18. Let Agents Work Autonomously

**Why**: Reduces human bottleneck, faster execution

**Trust the process**:
```
Agents will:
- Coordinate via messages
- Sequence work via task list
- Resolve minor issues themselves
- Escalate big decisions to you
```

**Don't**:
- Check every 5 minutes "How's it going?"
- Dictate specific implementation details
- Override agent decisions without good reason

**Do**:
- Set clear boundaries upfront
- Review outputs at checkpoints
- Provide feedback when needed

---

### 19. Balance Autonomy and Control

**Autonomy**: Agents make technical decisions within constraints

**Control**: You set requirements, review outputs, make business decisions

**Example**:
```
You control: "Gift cards must expire after 1 year"
Agents decide: "Store expires_at as datetime column with index"

You control: "Need CFDI invoice generation"
Agents decide: "Use Jobs for async processing, queue on SQS"
```

---

## Quality Assurance

### 20. Set Quality Standards Upfront

**In CLAUDE.md**:
```markdown
## Quality Standards

### Code
- PSR-12 compliance mandatory
- All methods have type hints
- All public methods have PHPDoc
- No methods > 50 lines
- Cyclomatic complexity < 10

### Testing
- Minimum 80% coverage
- All edge cases tested
- External APIs mocked
- Performance: API < 200ms

### Documentation
- All API endpoints documented
- All configuration explained
- Deployment steps complete
- Troubleshooting guide included
```

**Agents will follow these standards**

---

## Anti-Patterns to Avoid

### ❌ Don't: Rush Planning

**Wrong**:
```
User: "Just start coding, we'll figure it out"
```

**Right**:
```
User: "Let's do thorough planning first. I want:
- Complete data model
- Security requirements
- Integration approach clearly defined"
```

---

### ❌ Don't: Micromanage Agents

**Wrong**:
```
User: "Use exactly this SQL query..."
User: "No, put the method on line 47..."
User: "Change variable name to camelCase..."
```

**Right**:
```
User: "Optimize the slow query"
User: "Follow PSR-12 naming conventions"
(Let agents determine implementation)
```

---

### ❌ Don't: Approve Incomplete Work

**Wrong**:
```
User: "I guess this is okay..." (proceeds despite missing files)
```

**Right**:
```
User: "SECURITY.md is missing. Please create it before
       I approve planning phase."
```

---

### ❌ Don't: Skip CLAUDE.md

**Wrong**:
```
User: "Let's just start, Claude knows Laravel"
```

**Right**:
```
User: [Creates CLAUDE.md with project specifics]
User: "Use devteam to build X"
(Agents have context, work faster, use fewer tokens)
```

---

## Summary: The DevTeam Recipe

1. ✅ **Setup**: Enable Agent Teams, use Opus 4.6
2. ✅ **Context**: Create comprehensive CLAUDE.md
3. ✅ **Estimate**: Check costs before starting
4. ✅ **Planning**: Invest time, let agents debate
5. ✅ **Ownership**: Define clear file boundaries
6. ✅ **Dependencies**: Use task blocking properly
7. ✅ **Checkpoints**: Review thoroughly, approve thoughtfully
8. ✅ **Testing**: Specify coverage requirements
9. ✅ **Autonomy**: Let agents work, intervene only when needed
10. ✅ **Learn**: Document learnings, improve next time

**Result**: High-quality code, tests, and docs in hours instead of days

---

**Last Updated**: 2026-02-07
