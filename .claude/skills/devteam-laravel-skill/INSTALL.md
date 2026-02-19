# Installation Guide - DevTeam Laravel

Complete installation instructions for the DevTeam Laravel skill (Laravel/Filament specific).

---

## Prerequisites

### Required

1. **Claude Code**
   - Installed and working
   - Version with Agent Teams support

2. **Claude Opus 4.6**
   - Agent Teams feature available
   - Adaptive thinking support

3. **Environment Variables**
   ```bash
   export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
   export ANTHROPIC_MODEL="claude-opus-4-6"
   ```

### For Laravel Projects

1. **Laravel 12+**
2. **PHP 8.2+**
3. **Composer**
4. **Filament 3** (recommended)

---

## Installation Methods

### Method 1: User-Level Installation (Recommended)

Install skill for all your projects:

```bash
# 1. Download and extract
curl -L https://[download-url]/devteam-laravel-skill.tar.gz -o devteam-laravel-skill.tar.gz
tar -xzf devteam-laravel-skill.tar.gz

# 2. Move to Claude skills directory
mkdir -p ~/.claude/skills
mv devteam-laravel-skill ~/.claude/skills/devteam-laravel-laravel

# 3. Verify installation
ls -la ~/.claude/skills/devteam-laravel-laravel/

# 4. Set environment variables permanently
# For zsh (macOS default):
echo 'export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1' >> ~/.zshrc
echo 'export ANTHROPIC_MODEL="claude-opus-4-6"' >> ~/.zshrc
source ~/.zshrc

# For bash:
echo 'export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1' >> ~/.bashrc
echo 'export ANTHROPIC_MODEL="claude-opus-4-6"' >> ~/.bashrc
source ~/.bashrc
```

---

### Method 2: Project-Specific Installation

Install skill for a single project:

```bash
# In your Laravel project root
mkdir -p .claude/skills
cp -r /path/to/devteam-laravel-skill .claude/skills/devteam-laravel

# Or extract directly
tar -xzf devteam-laravel-skill.tar.gz -C .claude/skills/
mv .claude/skills/devteam-laravel-laravel-skill .claude/skills/devteam-laravel
```

---

### Method 3: Git Clone (For Development)

If you want to modify the skill:

```bash
# Clone to skills directory
cd ~/.claude/skills
git clone https://[repo-url]/devteam-laravel-skill.git devteam

# Or link to development directory
ln -s /path/to/your/devteam-laravel-skill ~/.claude/skills/devteam-laravel-laravel
```

---

## Verification

### 1. Check Skill Installation

```bash
# Start Claude Code
claude --model claude-opus-4-6

# In Claude, ask:
"What skills do you have available?"

# You should see "devteam-laravel" in the list with description
```

### 2. Check Environment Variables

```bash
# Verify Agent Teams enabled
echo $CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS
# Expected output: 1

# Verify model set
echo $ANTHROPIC_MODEL
# Expected output: claude-opus-4-6
```

### 3. Test Basic Functionality

```bash
# In Claude Code
"Use devteam to explain its workflow"

# Claude should:
# 1. Recognize the skill
# 2. Explain 4-phase workflow
# 3. Mention agent teams
```

---

## Laravel Project Setup

### Quick Setup with Script

```bash
# Navigate to your Laravel project
cd /path/to/your/laravel/project

# Run initialization script
~/.claude/skills/devteam-laravel-laravel/scripts/init_project.py

# This will:
# - Verify it's a Laravel project
# - Create CLAUDE.md template
# - Set up directory structure
# - Check environment
```

### Manual Setup

If you prefer manual setup:

1. **Create CLAUDE.md**
   ```bash
   cp ~/.claude/skills/devteam-laravel-laravel/examples/CLAUDE.md ./CLAUDE.md
   ```

2. **Edit CLAUDE.md**
   - Add your tech stack
   - Specify integrations (Facturama, SAP, etc.)
   - Document coding standards
   - Add anti-patterns to avoid

3. **Create Recommended Directories**
   ```bash
   mkdir -p app/Services
   mkdir -p app/Repositories
   mkdir -p tests/Unit/Models
   mkdir -p tests/Unit/Services
   mkdir -p tests/Feature/Api
   ```

4. **Validate Setup**
   ```bash
   ~/.claude/skills/devteam-laravel-laravel/scripts/validate_claude_md.py
   ```

---

## Usage

### Basic Usage

```bash
# Start Claude Code
claude --model claude-opus-4-6

# Use the skill
"Use devteam to build a Product catalog with Filament admin.
 Products should have: name, description, price, category."
```

### With Cost Estimation

```bash
# Before starting, estimate cost
~/.claude/skills/devteam-laravel-laravel/scripts/estimate_cost.py

# Interactive mode will ask:
# - Project size (small/medium/large)
# - Team size (2-3 agents)
# - Phases to skip
```

---

## Configuration

### Custom Configuration

Create `.devteam/config.json` in your project:

```json
{
  "version": "1.0.0",
  "settings": {
    "default_team_size": 3,
    "default_effort_levels": {
      "planning": "high",
      "development": "medium",
      "testing": "medium",
      "documentation": "low"
    },
    "phases": {
      "planning": {
        "enabled": true,
        "agents": ["architect", "business", "security"]
      },
      "development": {
        "enabled": true,
        "agents": ["backend", "frontend", "integration"]
      }
    }
  }
}
```

---

## Troubleshooting Installation

### Skill Not Found

**Problem**: Claude doesn't recognize "devteam-laravel" skill

**Solutions**:
```bash
# 1. Check skill directory
ls -la ~/.claude/skills/devteam-laravel-laravel/SKILL.md
# Should exist

# 2. Check directory name (must be "devteam-laravel" not "devteam-laravel-skill")
mv ~/.claude/skills/devteam-laravel-laravel-laravel-skill ~/.claude/skills/devteam-laravel-laravel

# 3. Restart Claude Code
exit
claude --model claude-opus-4-6
```

---

### Agent Teams Not Working

**Problem**: Claude works as single agent, not spawning teams

**Solutions**:
```bash
# 1. Check environment variable
echo $CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS
# Must output: 1

# 2. Set if missing
export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1

# 3. Add to shell profile permanently
echo 'export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1' >> ~/.zshrc

# 4. Restart Claude Code
```

---

### Wrong Model

**Problem**: Using Sonnet or older Opus

**Solutions**:
```bash
# 1. Check in-session
/status

# 2. Switch model
/model claude-opus-4-6

# 3. Set default
export ANTHROPIC_MODEL="claude-opus-4-6"

# 4. Permanently
echo 'export ANTHROPIC_MODEL="claude-opus-4-6"' >> ~/.zshrc
```

---

## Updating

### Update User-Level Installation

```bash
# 1. Backup current version
mv ~/.claude/skills/devteam-laravel-laravel ~/.claude/skills/devteam-laravel-laravel.backup

# 2. Install new version
tar -xzf devteam-laravel-skill-v1.1.0.tar.gz
mv devteam-laravel-skill ~/.claude/skills/devteam-laravel-laravel

# 3. Test
claude --model claude-opus-4-6
"What skills do you have?"
```

### Update Project-Specific Installation

```bash
# In project root
cd .claude/skills
rm -rf devteam
cp -r /path/to/new/devteam-laravel-skill ./devteam
```

---

## Uninstallation

### Remove User-Level

```bash
# Remove skill
rm -rf ~/.claude/skills/devteam-laravel-laravel

# Remove environment variables
# Edit ~/.zshrc or ~/.bashrc and remove:
# export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
# export ANTHROPIC_MODEL="claude-opus-4-6"
```

### Remove Project-Specific

```bash
# In project root
rm -rf .claude/skills/devteam-laravel

# Optionally remove CLAUDE.md and .devteam/
rm CLAUDE.md
rm -rf .devteam/
```

---

## Additional Resources

### Documentation

- `SKILL.md` - Complete skill documentation
- `README.md` - Quick start guide
- `references/workflow.md` - Detailed workflow
- `references/troubleshooting.md` - Common issues

### Scripts

- `scripts/init_project.py` - Initialize Laravel project
- `scripts/validate_claude_md.py` - Validate CLAUDE.md
- `scripts/estimate_cost.py` - Estimate token costs

### Examples

- `examples/CLAUDE.md` - Project context template
- `assets/workflow-diagrams.md` - Visual diagrams

---

## Getting Help

### Self-Service

1. Check `references/troubleshooting.md`
2. Review examples in `examples/`
3. Validate your setup with scripts

### Community

1. GitHub Issues (if open source)
2. Discord/Slack community (if available)

---

## Next Steps

After installation:

1. ✅ Verify environment setup
2. ✅ Initialize your Laravel project
3. ✅ Create comprehensive CLAUDE.md
4. ✅ Try a simple feature first
5. ✅ Learn patterns and iterate

---

**Installation complete! Ready to build with DevTeam! 🚀**
