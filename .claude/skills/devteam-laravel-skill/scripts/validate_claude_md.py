#!/usr/bin/env python3
"""
Validate CLAUDE.md format and completeness.

Checks for required sections and provides suggestions for improvement.
"""

import sys
from pathlib import Path
from typing import List, Tuple

class CLAUDEmdValidator:
    """Validator for CLAUDE.md files."""
    
    REQUIRED_SECTIONS = [
        "Overview",
        "Technology Stack",
        "Architecture",
        "Coding Standards",
        "File Structure",
    ]
    
    RECOMMENDED_SECTIONS = [
        "Key Integrations",
        "What NOT to Do",
        "Common Gotchas",
        "Testing",
        "Deployment",
    ]
    
    def __init__(self, filepath: Path):
        self.filepath = filepath
        self.content = ""
        self.sections = []
        self.errors = []
        self.warnings = []
        self.suggestions = []
    
    def validate(self) -> Tuple[bool, List[str], List[str], List[str]]:
        """Validate the CLAUDE.md file."""
        
        if not self.filepath.exists():
            self.errors.append(f"❌ File not found: {self.filepath}")
            return False, self.errors, self.warnings, self.suggestions
        
        with open(self.filepath, 'r') as f:
            self.content = f.read()
        
        self._extract_sections()
        self._check_required_sections()
        self._check_recommended_sections()
        self._check_content_quality()
        self._check_formatting()
        
        is_valid = len(self.errors) == 0
        return is_valid, self.errors, self.warnings, self.suggestions
    
    def _extract_sections(self):
        """Extract section headers from content."""
        lines = self.content.split('\n')
        for line in lines:
            if line.startswith('## '):
                section = line.replace('## ', '').strip()
                self.sections.append(section)
    
    def _check_required_sections(self):
        """Check for required sections."""
        for section in self.REQUIRED_SECTIONS:
            if not any(section.lower() in s.lower() for s in self.sections):
                self.errors.append(f"❌ Missing required section: {section}")
    
    def _check_recommended_sections(self):
        """Check for recommended sections."""
        for section in self.RECOMMENDED_SECTIONS:
            if not any(section.lower() in s.lower() for s in self.sections):
                self.warnings.append(f"⚠️  Missing recommended section: {section}")
    
    def _check_content_quality(self):
        """Check content quality indicators."""
        
        # Check length
        if len(self.content) < 500:
            self.warnings.append("⚠️  CLAUDE.md is very short (< 500 chars). Consider adding more detail.")
        
        # Check for placeholders
        placeholders = [
            "[Add",
            "[Brief description",
            "[TODO",
            "etc.",
        ]
        
        for placeholder in placeholders:
            if placeholder in self.content:
                self.warnings.append(f"⚠️  Found placeholder text: '{placeholder}'. Replace with actual content.")
        
        # Check for code examples
        if "```" not in self.content:
            self.suggestions.append("💡 Consider adding code examples in code blocks")
        
        # Check for anti-patterns section
        if "❌" not in self.content and "don't" not in self.content.lower():
            self.suggestions.append("💡 Consider adding 'What NOT to Do' section with anti-patterns")
    
    def _check_formatting(self):
        """Check formatting issues."""
        
        lines = self.content.split('\n')
        
        # Check for title
        if not lines[0].startswith('# '):
            self.warnings.append("⚠️  First line should be a top-level heading (# Title)")
        
        # Check for horizontal rules
        if '---' not in self.content:
            self.suggestions.append("💡 Consider using horizontal rules (---) to separate major sections")
        
        # Check for lists
        if '- ' not in self.content and '* ' not in self.content:
            self.suggestions.append("💡 Consider using bullet points for better readability")

def print_validation_results(is_valid: bool, errors: List[str], warnings: List[str], suggestions: List[str]):
    """Print validation results in a formatted way."""
    
    print("=" * 70)
    print("CLAUDE.md Validation Results")
    print("=" * 70)
    print()
    
    if errors:
        print("ERRORS (Must Fix):")
        print("-" * 70)
        for error in errors:
            print(f"  {error}")
        print()
    
    if warnings:
        print("WARNINGS (Should Fix):")
        print("-" * 70)
        for warning in warnings:
            print(f"  {warning}")
        print()
    
    if suggestions:
        print("SUGGESTIONS (Nice to Have):")
        print("-" * 70)
        for suggestion in suggestions:
            print(f"  {suggestion}")
        print()
    
    print("=" * 70)
    if is_valid:
        print("✅ CLAUDE.md is valid!")
        if warnings or suggestions:
            print("   Consider addressing warnings and suggestions for better results.")
    else:
        print("❌ CLAUDE.md has errors that must be fixed.")
    print("=" * 70)

def main():
    """Main validation function."""
    
    # Get file path
    if len(sys.argv) > 1:
        filepath = Path(sys.argv[1])
    else:
        filepath = Path.cwd() / "CLAUDE.md"
    
    print(f"\n🔍 Validating: {filepath}\n")
    
    # Validate
    validator = CLAUDEmdValidator(filepath)
    is_valid, errors, warnings, suggestions = validator.validate()
    
    # Print results
    print_validation_results(is_valid, errors, warnings, suggestions)
    
    # Exit code
    sys.exit(0 if is_valid else 1)

if __name__ == "__main__":
    main()
