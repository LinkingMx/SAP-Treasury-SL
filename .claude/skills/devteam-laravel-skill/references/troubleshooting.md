# Troubleshooting Guide

Common issues and solutions for DevTeam skill.

---

## Setup Issues

### Agent Teams Not Starting

**Symptom**: Claude doesn't create agent teams, works as single agent

**Causes**:
1. Environment variable not set
2. Wrong Claude model
3. Agent Teams feature not enabled

**Solutions**:

```bash
# 1. Verify environment variable
echo $CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS
# Should output: 1

# 2. Set if missing (zsh)
echo 'export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1' >> ~/.zshrc
source ~/.zshrc

# 2. Set if missing (bash)
echo 'export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1' >> ~/.bashrc
source ~/.bashrc

# 3. Verify model
/status
# Should show: claude-opus-4-6

# 4. Change model if needed
/model claude-opus-4-6

# 5. Set default model
export ANTHROPIC_MODEL="claude-opus-4-6"

# 6. Restart Claude Code
exit
claude --model claude-opus-4-6
```

---

### Wrong Model Being Used

**Symptom**: Agents using Sonnet instead of Opus, or older Opus version

**Solution**:

```bash
# Check current model
/status

# Switch to Opus 4.6
/model claude-opus-4-6

# Permanently set default
echo 'export ANTHROPIC_MODEL="claude-opus-4-6"' >> ~/.zshrc
source ~/.zshrc
```

---

## Workflow Issues

### High Token Usage / Cost

**Symptom**: Token usage higher than estimated

**Common Causes**:
1. Too many agents per phase
2. High effort level for all phases
3. Poorly defined tasks (causing exploration)
4. Missing or inadequate CLAUDE.md
5. Repeated failed attempts

**Solutions**:

1. **Reduce team size**:
```
Instead of 3 agents per phase, use 2
Savings: ~33% token reduction
```

2. **Lower effort levels**:
```bash
# Adjust effort during session
/model opus
# Use arrow keys to lower effort level

Planning: HIGH (required)
Development: MEDIUM
Testing: MEDIUM  
Documentation: LOW
```

3. **Better task definition**:
```
BAD: "Implement authentication"
GOOD: "Implement User model with password hashing in app/Models/User.php,
       using Laravel's built-in authentication. Follow PSR-12."
```

4. **Create comprehensive CLAUDE.md**:
```bash
# Use init script
python scripts/init_project.py /path/to/project

# Then edit CLAUDE.md with:
- Tech stack details
- Coding standards
- Common patterns
- Anti-patterns to avoid
```

5. **Skip unnecessary phases**:
```
"Use devteam but skip documentation, I'll write it manually"
```

---

### Teammates Editing Same Files (Conflicts)

**Symptom**: Agents overwriting each other's changes, merge conflicts

**Causes**:
1. Unclear file ownership
2. Missing task dependencies
3. Agents not coordinating

**Solutions**:

1. **Define clear file ownership in prompts**:
```
"For development phase:
- Backend owns: app/Models/, app/Services/, database/migrations/
- Frontend owns: app/Filament/, resources/views/
- Integration owns: routes/, tests/Feature/

Each agent must respect these boundaries."
```

2. **Use task dependencies**:
```
Task 3: Create Filament resource (frontend) [BLOCKED BY Task 2: Create model]

This prevents frontend from starting before model exists.
```

3. **Require coordination on shared files**:
```
"Before any agent modifies app/Providers/ or composer.json,
 they must coordinate via messages to avoid conflicts."
```

---

### Teammates Going Idle or Getting Stuck

**Symptom**: Teammate stops responding, task stuck "in progress"

**Solutions**:

1. **Interact directly with stuck teammate**:
```bash
# In-process mode
Shift+Up/Down to select teammate
Type message: "Are you stuck? What's blocking you?"

# Split-pane mode
Click into teammate's pane
Send message
```

2. **Reassign task**:
```
"Backend teammate is stuck on Task 5. Integration teammate,
 can you take over this task?"
```

3. **Simplify the task**:
```
Original: "Implement complete authentication system"
Simplified: "Implement login endpoint only. Other features later."
```

4. **Check for blocking issues**:
```
- Missing dependencies?
- Unclear requirements?
- Waiting for other agent's output?
```

---

### Agent Coordination Issues

**Symptom**: Agents duplicating work or missing dependencies

**Solutions**:

1. **Clearer task definitions**:
```
BAD: "Work on authentication"
GOOD: "Implement User model with authentication fields
       Location: app/Models/User.php
       Owner: backend agent
       Dependencies: None"
```

2. **Explicit dependencies**:
```
Task 2 is BLOCKED BY Task 1
Task 3 is BLOCKED BY Tasks 1 AND 2
```

3. **Lead intervention**:
```
You: "Backend and Frontend, coordinate on the User model
      interface before continuing. Frontend needs to know
      which fields are available."
```

---

### Partial Phase Completion

**Symptom**: Phase completes but some deliverables missing

**Solutions**:

1. **Review deliverables checklist before approving**:
```
Phase 1 Deliverables:
☐ REQUIREMENTS.md
☐ ARCHITECTURE.md
☐ DATA_MODEL.md
☐ API_CONTRACTS.md
☐ SECURITY.md
☐ TECH_STACK.md
```

2. **Ask Claude directly**:
```
"Did you complete all planned tasks for planning phase?"
"I don't see SECURITY.md. Was this intentionally skipped?"
```

3. **Request specific additions**:
```
"Before proceeding to development, please create SECURITY.md
 with authentication and authorization specifications."
```

---

## Quality Issues

### Tests Not Comprehensive

**Symptom**: Low code coverage, missing edge cases

**Solutions**:

1. **Specify coverage requirement upfront**:
```
"Use devteam to build X. For testing phase, ensure minimum
 85% code coverage and include tests for edge cases like
 expired cards, insufficient balance, invalid PINs."
```

2. **Review TESTING_REPORT.md carefully**:
```
Look for:
- Coverage percentage
- Uncovered code sections
- Missing test scenarios
```

3. **Request additional tests**:
```
"Testing phase showed 72% coverage. Please add tests for:
 - Error handling in PaymentService
 - Edge cases in GiftCard.redeem()
 - API rate limiting"
```

---

### Documentation Doesn't Match Implementation

**Symptom**: Docs describe features that don't exist or work differently

**Solutions**:

1. **Documentation agents should review code**:
```
"Tech writer, before documenting deployment, verify that
 your guide matches the actual scripts in the repository."
```

2. **Test documentation manually**:
```
Follow the installation guide step-by-step yourself.
Note any discrepancies.
```

3. **Request corrections**:
```
"The API docs say POST /api/cards but the code uses
 /api/gift-cards. Please fix the documentation."
```

---

### Code Quality Issues

**Symptom**: Code doesn't follow standards, has anti-patterns

**Solutions**:

1. **Improve CLAUDE.md**:
```markdown
## What NOT to Do

❌ Don't use UUIDs as primary keys (use auto-increment)
❌ Don't put business logic in controllers (use Services)
❌ Don't skip validation (use Form Requests)
```

2. **Request code review**:
```
"Before completing development phase, have all three
 development agents review each other's code for:
 - PSR-12 compliance
 - Proper use of Services vs Controllers
 - Adequate type hints"
```

---

## Environment Issues

### Split Pane Mode Not Working

**Symptom**: Can't use split pane mode, agents all in one terminal

**Cause**: Terminal doesn't support tmux or iTerm2

**Solutions**:

1. **Use in-process mode instead**:
```
This is the default and works everywhere.
Use Shift+Up/Down to select teammates.
```

2. **Switch to supported terminal**:
```
- macOS: Use iTerm2
- Linux: Install tmux

Then:
export CLAUDE_CODE_TEAMMATE_MODE=split-pane
```

---

### Permission Errors

**Symptom**: Agents can't create files, permission denied

**Solutions**:

1. **Check project directory permissions**:
```bash
ls -la /path/to/project
# Should be owned by your user

# Fix if needed
sudo chown -R $USER:$USER /path/to/project
```

2. **Check Claude Code permissions**:
```bash
# Laravel directories that need write permissions
chmod -R 775 storage bootstrap/cache
```

---

## Performance Issues

### Slow Agent Response Times

**Symptom**: Agents taking a long time to respond

**Solutions**:

1. **Reduce context size**:
```
- Keep CLAUDE.md focused (< 5000 chars)
- Don't include entire codebase in context
- Use specific file paths in tasks
```

2. **Check network connectivity**:
```bash
ping api.anthropic.com
# Should have low latency
```

3. **Reduce parallel agents**:
```
Instead of 3 agents per phase, use 2.
Less parallel work = faster individual responses.
```

---

## Best Practices to Avoid Issues

### Before Starting

1. ✅ **Verify setup**:
```bash
echo $CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS  # Should be 1
echo $ANTHROPIC_MODEL                        # Should be claude-opus-4-6
```

2. ✅ **Create CLAUDE.md**:
```bash
python scripts/init_project.py
# Then edit with project-specific details
```

3. ✅ **Clear requirements**:
```
Don't say: "Build an e-commerce site"
Do say: "Build a product catalog with categories, cart,
        and checkout using Filament admin and Stripe"
```

### During Execution

1. ✅ **Review each checkpoint carefully**
2. ✅ **Ask questions if unclear**
3. ✅ **Provide feedback early**
4. ✅ **Don't approve if deliverables missing**

### After Completion

1. ✅ **Update CLAUDE.md with learnings**
2. ✅ **Document any workarounds used**
3. ✅ **Note what worked well for next time**

---

## Getting Help

### If Issue Persists

1. Check `references/` for detailed documentation
2. Review `examples/` for working configurations
3. Validate CLAUDE.md with:
```bash
python scripts/validate_claude_md.py
```

4. Estimate costs with:
```bash
python scripts/estimate_cost.py
```

---

## Common Error Messages

### "Agent Teams not available"
→ Set `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`

### "Model not found: opus"
→ Use full model name: `claude-opus-4-6`

### "Rate limit exceeded"
→ Wait a few minutes, or reduce team size

### "Context window full"
→ Reduce CLAUDE.md size, or split work into smaller tasks

### "File already exists"
→ Agents tried to create same file. Check file ownership rules.

---

**Last Updated**: 2026-02-07
