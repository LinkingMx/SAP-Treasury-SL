# Tailwind CSS Development Skill

Activate this skill when styling components in Filament or React (shadcn/ui).

## Brand Colors - SAP Treasury SL (Costeño)

| Color | Hex | Usage |
|-------|-----|-------|
| Navy | `#191731` | Primary dark (backgrounds, light mode text) |
| Cream | `#EBDFC7` | Primary light (accents, dark mode text) |
| Gold | `#C5A059` | Accent (warnings, highlights, ring/focus) |

## Typography

- **Primary Font**: Open Sans
- Configured in both Filament and React frontend

## CSS Custom Variables

```css
--color-navy: #191731;
--color-cream: #EBDFC7;
--color-gold: #C5A059;
--radius: 0.625rem;
```

---

## Frontend (React + shadcn/ui)

### Light Mode

| Variable | Value | Description |
|----------|-------|-------------|
| `--background` | `#F8F6F1` | Main background (soft cream) |
| `--foreground` | `#191731` | Main text (navy) |
| `--primary` | `#3D3870` | Primary buttons/links |
| `--secondary` | `#EFE9DD` | Secondary elements |
| `--ring` | `#C5A059` | Focus rings (gold) |
| `--sidebar` | `#F5EFE6` | Sidebar background |

### Dark Mode

| Variable | Value | Description |
|----------|-------|-------------|
| `--background` | `#0D0F1A` | Main background (dark navy) |
| `--foreground` | `#EBDFC7` | Main text (cream) |
| `--primary` | `#EBDFC7` | Primary buttons/links (cream) |
| `--card` | `#191731` | Card background (navy) |
| `--border` | `#2A2650` | Subtle borders |
| `--ring` | `#C5A059` | Focus rings (gold) |

### shadcn/ui Components

Always use shadcn/ui components. They are pre-configured with the brand colors.

```tsx
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
```

### Usage Examples

```tsx
// Primary button
<Button>Guardar</Button>

// Secondary button
<Button variant="secondary">Cancelar</Button>

// Outline button
<Button variant="outline">Ver detalles</Button>

// Card with brand styling
<Card>
  <CardHeader>
    <CardTitle>Titulo</CardTitle>
  </CardHeader>
  <CardContent>
    Contenido aqui
  </CardContent>
</Card>
```

---

## Filament Admin Panel

### Color Palette

**Primary (Cream scale):**
- 50: `#FDFCFA`
- 300: `#EBDFC7` (Official cream)
- 950: `#3D352A`

**Gray (Navy tones):**
- 50: `#FDFCFA`
- 800: `#191731` (Official navy)
- 950: `#0D0F1A`

**Warning (Gold scale):**
- 50: `#FDF9F0`
- 500: `#C5A059` (Official gold)
- 950: `#2F2313`

### Special Styles

- **Default mode**: Dark Mode
- **Primary buttons**: Smooth transition with elevated hover
- **Active navigation**: 2px cream left border
- **Cards/Sections**: Semi-transparent background with blur (backdrop-filter)
- **Table headers**: Uppercase, letter-spacing 0.05em
- **Badges**: Uppercase, font-size 0.65rem
- **Notifications**: 3px cream left border
- **Login page**: Diagonal gradient navy-darker → navy

### Form Layout Rules

- **IMPORTANT**: All form sections MUST be full width (not side by side)
- Use `->columnSpanFull()` on ALL Section components
- Forms should flow vertically, one section below another
- Never place sections in a grid layout side by side

```php
// CORRECT - Full width sections
Section::make('Informacion')
    ->schema([...])
    ->columnSpanFull(),  // <-- REQUIRED

Section::make('Configuracion')
    ->schema([...])
    ->columnSpanFull(),  // <-- REQUIRED

// WRONG - Don't do this (sections side by side)
// This happens when columnSpanFull() is missing
```

---

## Logos and Branding

| Asset | Path |
|-------|------|
| Logo (light mode) | `/images/logo_white.svg` |
| Logo (dark mode) | `/images/logo_dark.svg` |
| Favicon | `/images/favicon.svg` |

Logo height: `2rem`

---

## Guidelines

### Do

- Use brand colors consistently
- Use shadcn/ui components in React
- Use CSS variables for colors
- Support both light and dark modes
- Use Open Sans font
- Use `--radius` for border-radius consistency

### Don't

- Don't use arbitrary color values outside the brand palette
- Don't create custom components when shadcn/ui has one
- Don't hardcode colors - use variables
- Don't ignore dark mode support

---

## Quick Reference

```tsx
// Brand colors in Tailwind classes (if configured)
className="bg-navy text-cream"
className="border-gold ring-gold"

// Using CSS variables
className="bg-[var(--background)] text-[var(--foreground)]"
className="ring-[var(--ring)]"

// Focus states with gold ring
className="focus:ring-2 focus:ring-gold focus:ring-offset-2"
```

## Checklist

- [ ] Using brand colors (navy, cream, gold)
- [ ] Using Open Sans font
- [ ] Using shadcn/ui components in React
- [ ] Supporting dark mode
- [ ] Using CSS variables for colors
- [ ] Focus states use gold ring color
