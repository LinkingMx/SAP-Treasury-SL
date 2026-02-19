#!/usr/bin/env python3
"""
Estimate token usage and cost for DevTeam Laravel workflow.

Provides cost estimates based on project characteristics.
"""

import sys
import json
from typing import Dict

# Token estimates per phase (in thousands)
PHASE_ESTIMATES = {
    "planning": {
        "small": {"min": 30, "avg": 50, "max": 80},
        "medium": {"min": 80, "avg": 150, "max": 200},
        "large": {"min": 150, "avg": 250, "max": 400},
    },
    "development": {
        "small": {"min": 80, "avg": 150, "max": 250},
        "medium": {"min": 250, "avg": 400, "max": 600},
        "large": {"min": 500, "avg": 1000, "max": 1500},
    },
    "testing": {
        "small": {"min": 40, "avg": 80, "max": 120},
        "medium": {"min": 100, "avg": 200, "max": 300},
        "large": {"min": 200, "avg": 400, "max": 600},
    },
    "documentation": {
        "small": {"min": 20, "avg": 40, "max": 60},
        "medium": {"min": 50, "avg": 100, "max": 150},
        "large": {"min": 100, "avg": 200, "max": 300},
    },
}

# Opus 4.6 pricing (per 1M tokens)
OPUS_PRICING = {
    "input": 5.00,
    "output": 25.00,
}

# Assume 80% output, 20% input (typical for code generation)
OUTPUT_RATIO = 0.8
INPUT_RATIO = 0.2

class CostEstimator:
    """Estimate costs for DevTeam Laravel workflows."""
    
    def __init__(self):
        self.project_size = "medium"
        self.phases = ["planning", "development", "testing", "documentation"]
        self.team_size = 3
        self.custom_multiplier = 1.0
    
    def estimate(self) -> Dict:
        """Generate cost estimate."""
        
        total_tokens_min = 0
        total_tokens_avg = 0
        total_tokens_max = 0
        
        phase_details = {}
        
        for phase in self.phases:
            estimates = PHASE_ESTIMATES[phase][self.project_size]
            
            # Apply team size multiplier (3 agents is baseline)
            team_multiplier = self.team_size / 3.0
            
            min_tokens = estimates["min"] * team_multiplier * self.custom_multiplier
            avg_tokens = estimates["avg"] * team_multiplier * self.custom_multiplier
            max_tokens = estimates["max"] * team_multiplier * self.custom_multiplier
            
            total_tokens_min += min_tokens
            total_tokens_avg += avg_tokens
            total_tokens_max += max_tokens
            
            phase_details[phase] = {
                "min_tokens": int(min_tokens * 1000),
                "avg_tokens": int(avg_tokens * 1000),
                "max_tokens": int(max_tokens * 1000),
            }
        
        # Calculate costs (in dollars)
        def calculate_cost(tokens_k):
            tokens = tokens_k * 1000
            input_tokens = tokens * INPUT_RATIO
            output_tokens = tokens * OUTPUT_RATIO
            
            input_cost = (input_tokens / 1_000_000) * OPUS_PRICING["input"]
            output_cost = (output_tokens / 1_000_000) * OPUS_PRICING["output"]
            
            return input_cost + output_cost
        
        cost_min = calculate_cost(total_tokens_min)
        cost_avg = calculate_cost(total_tokens_avg)
        cost_max = calculate_cost(total_tokens_max)
        
        # Estimate time (rough approximation: 1M tokens ~= 45-60 minutes)
        time_min_hours = (total_tokens_min / 1000) * 0.75
        time_avg_hours = (total_tokens_avg / 1000) * 0.75
        time_max_hours = (total_tokens_max / 1000) * 0.75
        
        return {
            "project_size": self.project_size,
            "team_size": self.team_size,
            "phases": self.phases,
            "tokens": {
                "min": int(total_tokens_min * 1000),
                "avg": int(total_tokens_avg * 1000),
                "max": int(total_tokens_max * 1000),
            },
            "cost_usd": {
                "min": round(cost_min, 2),
                "avg": round(cost_avg, 2),
                "max": round(cost_max, 2),
            },
            "time_hours": {
                "min": round(time_min_hours, 1),
                "avg": round(time_avg_hours, 1),
                "max": round(time_max_hours, 1),
            },
            "phase_details": phase_details,
        }

def print_estimate(estimate: Dict):
    """Print estimate in a formatted way."""
    
    print("=" * 70)
    print("DevTeam Laravel Cost Estimate")
    print("=" * 70)
    print()
    
    print(f"Project Size: {estimate['project_size'].upper()}")
    print(f"Team Size: {estimate['team_size']} agents per phase")
    print(f"Phases: {', '.join(estimate['phases'])}")
    print()
    
    print("=" * 70)
    print("ESTIMATE RANGES")
    print("=" * 70)
    print()
    
    print("Total Tokens:")
    print(f"  Minimum:  {estimate['tokens']['min']:>10,} tokens")
    print(f"  Average:  {estimate['tokens']['avg']:>10,} tokens")
    print(f"  Maximum:  {estimate['tokens']['max']:>10,} tokens")
    print()
    
    print("Total Cost (Opus 4.6):")
    print(f"  Minimum:  ${estimate['cost_usd']['min']:>9.2f}")
    print(f"  Average:  ${estimate['cost_usd']['avg']:>9.2f}")
    print(f"  Maximum:  ${estimate['cost_usd']['max']:>9.2f}")
    print()
    
    print("Estimated Time:")
    print(f"  Minimum:  {estimate['time_hours']['min']:>9.1f} hours")
    print(f"  Average:  {estimate['time_hours']['avg']:>9.1f} hours")
    print(f"  Maximum:  {estimate['time_hours']['max']:>9.1f} hours")
    print()
    
    print("=" * 70)
    print("BREAKDOWN BY PHASE")
    print("=" * 70)
    print()
    
    for phase, details in estimate['phase_details'].items():
        print(f"{phase.upper()}:")
        print(f"  Min: {details['min_tokens']:>8,} tokens")
        print(f"  Avg: {details['avg_tokens']:>8,} tokens")
        print(f"  Max: {details['max_tokens']:>8,} tokens")
        print()
    
    print("=" * 70)
    print("NOTES")
    print("=" * 70)
    print()
    print("• Estimates based on Opus 4.6 pricing ($5 input / $25 output per 1M tokens)")
    print("• Assumes 80% output, 20% input token ratio")
    print("• Actual costs vary based on:")
    print("  - Task complexity")
    print("  - Effort levels used")
    print("  - Number of iterations")
    print("  - CLAUDE.md quality (better context = fewer tokens)")
    print()
    print("• Time estimates are approximate")
    print("• Most work is autonomous (minimal human intervention)")
    print()

def interactive_mode():
    """Interactive mode for cost estimation."""
    
    print("\n🧮 DevTeam Laravel Cost Estimator (Interactive Mode)\n")
    
    estimator = CostEstimator()
    
    # Project size
    print("Project Size:")
    print("  1. Small   (single resource, simple CRUD)")
    print("  2. Medium  (multiple resources, some complexity)")
    print("  3. Large   (complex system, many integrations)")
    
    size_choice = input("\nSelect project size [1-3] (default: 2): ").strip()
    size_map = {"1": "small", "2": "medium", "3": "large", "": "medium"}
    estimator.project_size = size_map.get(size_choice, "medium")
    
    # Team size
    team_input = input("Team size per phase (2-3 agents, default: 3): ").strip()
    try:
        estimator.team_size = int(team_input) if team_input else 3
        estimator.team_size = max(2, min(3, estimator.team_size))
    except:
        estimator.team_size = 3
    
    # Skip phases?
    skip_phases = input("Skip any phases? (e.g., 'testing,documentation'): ").strip()
    if skip_phases:
        skip_list = [p.strip() for p in skip_phases.split(',')]
        estimator.phases = [p for p in estimator.phases if p not in skip_list]
    
    print()
    
    # Generate and print estimate
    estimate = estimator.estimate()
    print_estimate(estimate)

def main():
    """Main function."""
    
    if len(sys.argv) > 1:
        # JSON output mode (for scripting)
        estimator = CostEstimator()
        
        # Parse arguments
        for arg in sys.argv[1:]:
            if arg.startswith("--size="):
                estimator.project_size = arg.split("=")[1]
            elif arg.startswith("--team-size="):
                estimator.team_size = int(arg.split("=")[1])
            elif arg == "--json":
                estimate = estimator.estimate()
                print(json.dumps(estimate, indent=2))
                return
        
        estimate = estimator.estimate()
        print_estimate(estimate)
    else:
        # Interactive mode
        interactive_mode()

if __name__ == "__main__":
    main()
