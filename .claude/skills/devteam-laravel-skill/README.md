# DevTeam Laravel Skill

AI Development Team orchestration specifically for Laravel/Filament projects using Claude Opus 4.6 Agent Teams.

## Quick Start

```bash
export CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1
export ANTHROPIC_MODEL="claude-opus-4-6"
claude --model claude-opus-4-6
```

Then: `"Build a gift card system with Filament admin and CFDI invoicing"`

## What's Included

```
devteam-laravel-skill/
├── SKILL.md                # Main skill file (Laravel-specific)
├── README.md              # This file
├── references/
│   ├── workflow.md        # Complete workflow guide
│   ├── laravel-patterns.md # Laravel/Filament patterns
│   └── cost-optimization.md # Token usage strategies
├── examples/
│   └── CLAUDE.md          # Laravel project context template
└── scripts/               # Helper scripts
```

## Installation

### Option 1: User Skills Directory
```bash
mkdir -p ~/.claude/skills/
cp -r devteam-laravel-skill ~/.claude/skills/devteam-laravel
```

### Option 2: Project-Specific
```bash
mkdir -p .claude/skills
cp -r devteam-laravel-skill .claude/skills/devteam-laravel
```

## Verification

```bash
claude
# Ask: "What skills do you have available?"
# Should see "devteam-laravel" listed
```

## Usage

```
"Use devteam-laravel to build [feature description]"
```

DevTeam Laravel will:
1. Spawn agent teams for each phase
2. Generate specs, code, tests, docs
3. Present checkpoints for approval
4. Deliver production-ready Laravel code

## Requirements

- Claude Opus 4.6
- Agent Teams enabled
- Laravel 12+ project
- CLAUDE.md in project root (recommended)

## Documentation

- **SKILL.md**: Complete skill documentation
- **references/workflow.md**: Detailed phase-by-phase workflow
- **references/laravel-patterns.md**: Laravel/Filament implementations
- **examples/CLAUDE.md**: Project context template

## Cost Estimates

- Small feature: ~$20 (400K tokens, 1-2 hours)
- Medium feature: ~$42 (850K tokens, 3-4 hours)
- Large system: ~$150 (3M tokens, 8-12 hours)

## Support

1. Check references/ for detailed documentation
2. Review examples/ for templates
3. Create CLAUDE.md in your project for better results

## Version

v1.0.0 (2026-02-07)

---

Ready to build Laravel applications with AI teams! 🚀
