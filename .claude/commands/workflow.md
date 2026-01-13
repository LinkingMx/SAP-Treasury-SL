---
description: Flujo de trabajo multi-agente en 4 fases para desarrollo de software con planificacion, implementacion, testing y documentacion
allowed-tools: Read, Write, Edit, Bash, Grep, Glob, Task, TodoWrite, AskUserQuestion
---

# Multi-Agent Workflow - Orquestador Principal

Este comando ejecuta un flujo de trabajo estructurado en 4 fases con agentes especializados para desarrollo de software de alta calidad.

## Agentes Disponibles

Los agentes estan definidos en `.claude/agents/`:
- `planificador.md` - Fase 1: Analisis y planificacion
- `implementador.md` - Fase 2: Desarrollo con documentacion
- `tester.md` - Fase 3: Testing y validacion
- `documentador.md` - Fase 4: Documentacion y commits

## Fases del Workflow

1. **Fase 1 - Planificacion**: Analisis y creacion del plan (ver agents/planificador.md)
2. **Fase 2 - Implementacion**: Desarrollo del codigo (ver agents/implementador.md)
3. **Fase 3 - Testing**: Pruebas y validacion (ver agents/tester.md)
4. **Fase 4 - Documentacion**: Documentacion y commit (ver agents/documentador.md)

## Instrucciones de Ejecucion

### FASE 1: PLANIFICACION

Antes de cualquier codigo:

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

### FASE 2: IMPLEMENTACION

Solo despues de autorizacion:

1. **Consultar documentacion** usando search-docs de Laravel Boost
2. **Implementar cambio por cambio** segun el plan
3. **Seguir convenciones** del codigo existente
4. **DETENERSE y preguntar** si hay ambiguedad
5. **Validar** que todos los cambios del plan fueron implementados

### FASE 3: TESTING

Probar exhaustivamente:

1. **Happy Path**: Flujo normal esperado
2. **Edge Cases**: Limites y casos extremos
3. **Error Handling**: Manejo de errores
4. **Performance**: Tiempos de respuesta

Generar reporte con:
- Casos probados y resultados
- Issues encontrados (si hay)
- Metricas de performance
- Decision: Aprobar o Requiere correcciones

### FASE 4: DOCUMENTACION

Documentar completamente:

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

Co-authored-by: Claude AI (Multi-Agent Workflow)
```

Tipos: feat, fix, docs, refactor, test, perf, chore

## Reglas Criticas

- NO asumir informacion no confirmada
- NO saltar pasos del flujo
- NO generar codigo antes de autorizacion (Fase 1)
- NO omitir consulta a documentacion (Fase 2)
- NO aprobar con issues graves (Fase 3)
- NO inventar informacion no proporcionada (Fase 4)

## Inicio del Workflow

Comenzando Fase 1 - Planificacion...

Por favor, describe la funcionalidad o cambio que necesitas implementar.
