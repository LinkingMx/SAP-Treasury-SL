# Cost Optimization Reference

Strategies to minimize token usage while maintaining quality.

## Effort Level Strategy

| Phase | Effort | Token Savings | When to Use |
|-------|--------|---------------|-------------|
| Planning | HIGH | Baseline | Always - critical foundation |
| Development | MEDIUM | 76% vs HIGH | Default for coding |
| Testing | MEDIUM | 76% vs HIGH | Default for validation |
| Documentation | LOW | 85% vs HIGH | Routine writing tasks |

## Team Size Optimization

**Standard (3 agents/phase):** Best quality
**Budget (2 agents/phase):** 33% savings

## Estimated Costs

| Project | Phases | Tokens | Cost ($) | Time (hrs) |
|---------|--------|--------|----------|------------|
| Small | 2-3 | 400K | $20 | 1-2 |
| Medium | 4 | 850K | $42 | 3-4 |
| Large | 4+ | 3M | $150 | 8-12 |

## Tips

1. Clear CLAUDE.md reduces context loading
2. Specific prompts prevent exploration
3. File ownership prevents duplicate reads
4. Task dependencies sequence work efficiently
5. Lower effort for non-critical phases
