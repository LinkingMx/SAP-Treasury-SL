# DevTeam Workflow Diagrams

Visual representations of the DevTeam workflow.

## Complete Workflow Overview

```mermaid
graph TB
    Start([User Request]) --> Analyze{Analyze Request}
    Analyze --> Phase1[Phase 1: Planning]
    
    Phase1 --> P1Team[Spawn Agent Team]
    P1Team --> P1Arch[Architect Agent]
    P1Team --> P1Bus[Business Agent]
    P1Team --> P1Sec[Security Agent]
    
    P1Arch --> P1Collab{Agents Collaborate}
    P1Bus --> P1Collab
    P1Sec --> P1Collab
    
    P1Collab --> P1Output[/planning/<br/>REQUIREMENTS.md<br/>ARCHITECTURE.md<br/>DATA_MODEL.md/]
    
    P1Output --> Check1{User Approval?}
    Check1 -->|No| P1Feedback[Adjust Planning]
    P1Feedback --> Phase1
    Check1 -->|Yes| Phase2[Phase 2: Development]
    
    Phase2 --> P2Team[Spawn Agent Team]
    P2Team --> P2Back[Backend Agent]
    P2Team --> P2Front[Frontend Agent]
    P2Team --> P2Int[Integration Agent]
    
    P2Back --> P2Collab{Agents Collaborate}
    P2Front --> P2Collab
    P2Int --> P2Collab
    
    P2Collab --> P2Output[/app/<br/>Models, Services<br/>Filament Resources<br/>Tests/]
    
    P2Output --> Check2{User Approval?}
    Check2 -->|No| P2Feedback[Fix Issues]
    P2Feedback --> Phase2
    Check2 -->|Yes| Phase3[Phase 3: Testing]
    
    Phase3 --> P3Team[Spawn Agent Team]
    P3Team --> P3Unit[Unit Tester]
    P3Team --> P3Int[Integration Tester]
    P3Team --> P3E2E[E2E Tester]
    
    P3Unit --> P3Collab{Agents Collaborate}
    P3Int --> P3Collab
    P3E2E --> P3Collab
    
    P3Collab --> P3Output[/tests/<br/>Unit Tests<br/>Feature Tests<br/>Coverage Report/]
    
    P3Output --> Check3{User Approval?}
    Check3 -->|No| P3Feedback[Add Tests]
    P3Feedback --> Phase3
    Check3 -->|Yes| Phase4[Phase 4: Documentation]
    
    Phase4 --> P4Team[Spawn Agent Team]
    P4Team --> P4API[API Docs Agent]
    P4Team --> P4Tech[Tech Writer]
    P4Team --> P4User[User Guide Writer]
    
    P4API --> P4Collab{Agents Collaborate}
    P4Tech --> P4Collab
    P4User --> P4Collab
    
    P4Collab --> P4Output[/docs/<br/>API Docs<br/>Technical Docs<br/>User Guides/]
    
    P4Output --> Complete([Workflow Complete])
    
    style Phase1 fill:#e1f5ff
    style Phase2 fill:#fff4e1
    style Phase3 fill:#e8f5e9
    style Phase4 fill:#f3e5f5
    style Complete fill:#c8e6c9
```

## Phase 1: Planning Team Collaboration

```mermaid
sequenceDiagram
    participant User
    participant Lead as Lead (Claude)
    participant Arch as Architect Agent
    participant Bus as Business Agent
    participant Sec as Security Agent
    participant TaskList as Shared Task List
    
    User->>Lead: "Build gift card system with CFDI"
    Lead->>Lead: Analyze requirements
    Lead->>Arch: Spawn architect agent
    Lead->>Bus: Spawn business agent
    Lead->>Sec: Spawn security agent
    
    Arch->>TaskList: Create tasks 1-3
    Bus->>TaskList: Claim task 2
    Sec->>TaskList: Claim task 3
    Arch->>TaskList: Claim task 1
    
    Arch->>Bus: "What's the card expiration policy?"
    Bus->>Arch: "1 year from purchase date"
    
    Sec->>Arch: "Recommend rate limiting for API"
    Arch->>Sec: "Agreed, adding to API spec"
    
    Arch->>TaskList: Complete task 1
    Bus->>TaskList: Complete task 2
    Sec->>TaskList: Complete task 3
    
    Arch->>TaskList: Create tasks 4-6
    Arch->>Bus: "Review data model for consistency"
    Bus->>Arch: "Looks good, relationships correct"
    
    Arch->>TaskList: Complete all tasks
    Lead->>Lead: Synthesize results
    Lead->>User: Present planning documents
    User->>Lead: Approve
```

## Phase 2: Development File Ownership

```mermaid
graph LR
    subgraph Backend Agent Owns
        B1[app/Models/]
        B2[app/Services/]
        B3[app/Repositories/]
        B4[database/migrations/]
        B5[tests/Unit/]
    end
    
    subgraph Frontend Agent Owns
        F1[app/Filament/]
        F2[resources/views/]
        F3[resources/js/]
    end
    
    subgraph Integration Agent Owns
        I1[routes/]
        I2[app/Http/Middleware/]
        I3[tests/Feature/]
    end
    
    subgraph Shared Coordination Required
        S1[app/Providers/]
        S2[composer.json]
        S3[package.json]
    end
    
    style Backend Agent Owns fill:#e3f2fd
    style Frontend Agent Owns fill:#fff3e0
    style Integration Agent Owns fill:#e8f5e9
    style Shared Coordination Required fill:#fce4ec
```

## Agent Communication Pattern

```mermaid
graph TD
    A[Agent discovers need] --> B{Can I proceed?}
    B -->|Yes, within my ownership| C[Execute task]
    B -->|No, affects other agent| D[Send message to relevant agent]
    
    D --> E[Other agent responds]
    E --> F{Agreement?}
    F -->|Yes| C
    F -->|No| G[Escalate to Lead]
    
    G --> H[Lead makes decision]
    H --> C
    
    C --> I[Update shared task list]
    I --> J{More work?}
    J -->|Yes| A
    J -->|No| K[Mark phase complete]
```

## Cost Optimization Decision Tree

```mermaid
graph TD
    Start([Task to execute]) --> Check{Task type?}
    
    Check -->|Planning/Architecture| High[Use HIGH effort]
    Check -->|Development/Testing| Medium[Use MEDIUM effort]
    Check -->|Documentation| Low[Use LOW effort]
    
    High --> Team1{Team size?}
    Medium --> Team2{Team size?}
    Low --> Team3{Team size?}
    
    Team1 -->|Complex| T3a[3 agents]
    Team1 -->|Simple| T2a[2 agents]
    
    Team2 -->|Complex| T3b[3 agents]
    Team2 -->|Simple| T2b[2 agents]
    
    Team3 -->|Complex| T3c[3 agents]
    Team3 -->|Simple| T2c[2 agents]
    
    T3a --> Estimate1[~150K tokens]
    T2a --> Estimate2[~100K tokens]
    T3b --> Estimate3[~400K tokens]
    T2b --> Estimate4[~270K tokens]
    T3c --> Estimate5[~100K tokens]
    T2c --> Estimate6[~65K tokens]
```

## Feedback Loop Pattern

```mermaid
sequenceDiagram
    participant Testing as Testing Phase
    participant Lead
    participant User
    participant Planning as Planning Phase (new team)
    
    Testing->>Testing: Discover missing validation
    Testing->>Lead: Create /feedback/ISSUE-001.md
    Lead->>User: "Testing found gap in specs. Re-run Planning?"
    User->>Lead: "Yes, fix the validation spec"
    Lead->>Planning: Spawn new Planning team
    Planning->>Planning: Address ONLY validation spec
    Planning->>Lead: Updated SECURITY.md
    Lead->>User: "Validation spec updated. Continue Testing?"
    User->>Lead: "Yes"
```

## Token Usage by Phase (Medium Project)

```mermaid
pie title Token Distribution
    "Planning (18%)" : 150
    "Development (47%)" : 400
    "Testing (24%)" : 200
    "Documentation (11%)" : 100
```

## Time Distribution (Medium Project)

```mermaid
gantt
    title DevTeam Timeline (Medium Project)
    dateFormat HH:mm
    axisFormat %H:%M
    
    section Phase 1
    Planning Team Spawn    :a1, 00:00, 5m
    Agent Collaboration    :a2, after a1, 30m
    Synthesis & Checkpoint :a3, after a2, 10m
    
    section Phase 2
    Development Team Spawn :b1, after a3, 5m
    Agent Collaboration    :b2, after b1, 60m
    Synthesis & Checkpoint :b3, after b2, 10m
    
    section Phase 3
    Testing Team Spawn     :c1, after b3, 5m
    Agent Collaboration    :c2, after c1, 45m
    Synthesis & Checkpoint :c3, after c2, 10m
    
    section Phase 4
    Docs Team Spawn        :d1, after c3, 5m
    Agent Collaboration    :d2, after d1, 30m
    Final Synthesis        :d3, after d2, 10m
```

---

## How to Use These Diagrams

1. **Copy the mermaid code blocks** to visualize in:
   - GitHub (renders automatically)
   - Mermaid Live Editor: https://mermaid.live
   - VS Code with Mermaid extension
   - Documentation tools (MkDocs, etc.)

2. **Customize for your project:**
   - Adjust agent names
   - Add project-specific phases
   - Modify collaboration patterns

3. **Share with team:**
   - Include in project documentation
   - Use in presentations
   - Reference in CLAUDE.md
