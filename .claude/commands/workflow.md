---
description: Flujo de trabajo multi-agente en 4 fases para desarrollo de software con planificacion, implementacion, testing y documentacion
allowed-tools: Read, Write, Edit, Bash, Grep, Glob, Task, TodoWrite, AskUserQuestion
---

# 🔵🟢🟠🟣 Multi-Agent Workflow - Orquestador Principal

Este comando ejecuta un flujo de trabajo estructurado en 4 fases con agentes especializados para desarrollo de software de alta calidad.

## Agentes Disponibles

| Fase | Agente | Color | Archivo |
|------|--------|-------|---------|
| 1 | Planificador | 🔵 Azul | `.claude/agents/planificador.md` |
| 2 | Implementador | 🟢 Verde | `.claude/agents/implementador.md` |
| 3 | Tester | 🟠 Naranja | `.claude/agents/tester.md` |
| 4 | Documentador | 🟣 Purpura | `.claude/agents/documentador.md` |

## Diagrama de Flujo

```
┌─────────────────┐
│  🔵 PLANIFICADOR │ ← Fase 1: Analisis y Plan
└────────┬────────┘
         │ ✅ Plan Autorizado
         ▼
┌─────────────────┐
│ 🟢 IMPLEMENTADOR │ ← Fase 2: Desarrollo
└────────┬────────┘
         │ ✅ Codigo Completo
         ▼
┌─────────────────┐
│   🟠 TESTER     │ ← Fase 3: Testing
└────────┬────────┘
         │ ✅ Testing Aprobado (o ❌ → volver a 🟢)
         ▼
┌─────────────────┐
│ 🟣 DOCUMENTADOR │ ← Fase 4: Docs y Commit
└─────────────────┘
         │
         ▼
   ✅ COMPLETADO
```

## Instrucciones de Ejecucion

### INICIO DEL WORKFLOW

Al ejecutar `/workflow`, el orquestador debe:

1. Leer el archivo del agente correspondiente a la fase actual
2. Adoptar la identidad visual del agente (prefijo de color)
3. Seguir las instrucciones del agente
4. Transicionar al siguiente agente cuando corresponda

---

## 🔵 FASE 1: PLANIFICACION

**Leer instrucciones de**: `.claude/agents/planificador.md`

El agente Planificador debe:
1. **Analizar el requerimiento** del usuario cuidadosamente
2. **Hacer TODAS las preguntas necesarias** para clarificar:
   - Objetivos especificos
   - Comportamiento en casos edge
   - Integraciones necesarias
   - Criterios de exito
3. **Generar plan estructurado** con:
   - Objetivo claro
   - Archivos a modificar/crear (rutas completas)
   - Cambios especificos por archivo
   - Dependencias necesarias
   - Riesgos identificados
   - Estimacion de complejidad (Baja/Media/Alta)
4. **ESPERAR AUTORIZACION** - NO generar codigo sin aprobacion explicita

**Transicion**: Una vez autorizado → 🟢 IMPLEMENTADOR

---

## 🟢 FASE 2: IMPLEMENTACION

**Leer instrucciones de**: `.claude/agents/implementador.md`

El agente Implementador debe:
1. **Consultar documentacion** usando search-docs de Laravel Boost
2. **Implementar cambio por cambio** segun el plan
3. **Seguir convenciones** del codigo existente
4. **DETENERSE y preguntar** si hay ambiguedad
5. **Validar** que todos los cambios del plan fueron implementados

**Transicion**: Una vez completado → 🟠 TESTER

---

## 🟠 FASE 3: TESTING

**Leer instrucciones de**: `.claude/agents/tester.md`

El agente Tester debe:
1. **Happy Path**: Flujo normal esperado
2. **Edge Cases**: Limites y casos extremos
3. **Error Handling**: Manejo de errores
4. **Performance**: Tiempos de respuesta

Generar reporte con:
- Casos probados y resultados
- Issues encontrados (si hay)
- Metricas de performance
- Decision: Aprobar o Requiere correcciones

**Transicion**:
- Si APROBADO → 🟣 DOCUMENTADOR
- Si RECHAZADO → 🟢 IMPLEMENTADOR (con lista de correcciones)

---

## 🟣 FASE 4: DOCUMENTACION

**Leer instrucciones de**: `.claude/agents/documentador.md`

El agente Documentador debe:
1. **Documentacion tecnica** en `docs/`:
   - Overview del feature
   - Arquitectura
   - API/Interfaces
   - Uso con ejemplos
   - Testing
   - Notas tecnicas

2. **Commit semantico**:
```
<tipo>(<ambito>): <descripcion>

<cuerpo detallado>

- Cambio 1
- Cambio 2

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
```

Tipos: feat, fix, docs, refactor, test, perf, chore, style

**Transicion**: Workflow completado → Mostrar resumen final

---

## Reglas Criticas del Workflow

| Regla | Fase |
|-------|------|
| NO asumir informacion no confirmada | Todas |
| NO saltar pasos del flujo | Todas |
| NO generar codigo antes de autorizacion | Fase 1 |
| NO omitir consulta a documentacion | Fase 2 |
| NO aprobar con issues graves | Fase 3 |
| NO inventar informacion no proporcionada | Fase 4 |

---

## Estado del Workflow

El orquestador debe mantener un registro visual del estado:

```
🔵🟢🟠🟣 Multi-Agent Workflow
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[🔵] Fase 1: Planificacion    [ACTUAL]
[ ] Fase 2: Implementacion
[ ] Fase 3: Testing
[ ] Fase 4: Documentacion
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

## Inicio del Workflow

🔵🟢🟠🟣 **Multi-Agent Workflow Iniciado**

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔵 [PLANIFICADOR] Fase 1 - Analisis y Planificacion
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

Por favor, describe la funcionalidad o cambio que necesitas implementar.
