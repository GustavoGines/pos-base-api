# Original User Request

## 2026-09-26T21:04:39Z

# Teamwork Project Prompt — Draft

> Status: Launched
> Goal: Craft prompt → get user approval → delegate to teamwork_preview
> Requested team: [none — teamwork routes from the description]

Auditar exhaustivamente el código del backend y el historial de 3 conversaciones previas para verificar su correcta implementación, detectando duplicidades o código sobrescrito. Consolidar el reporte `Auditoria_Reporte_Backend.md` dentro de `backend_tech_debt_report.md`, documentando todos los hallazgos sin modificar el código fuente.

Working directory: c:\laragon\www\Sistema_POS\pos-backend
Integrity mode: development

## Requirements

### R1. Verificación Estricta y Análisis
Analizar el código fuente actual y cruzarlo con el historial de las 3 conversaciones indicadas:
1. `97c16b6a-121d-46ae-9c8c-78b29e9f97ab` (Backend Technical Debt Audit)
2. `7f640581-e3f6-4f32-b41b-b9f2ef50de7b` (Auditoría De Código Backend)
3. `aa775c2c-a27c-4f37-b84d-628573e37254` (Comprehensive Software Code Audit)
Se debe realizar una revisión de código exhaustiva (línea por línea), utilizando análisis estático y pruebas automatizadas si están disponibles en el proyecto, para identificar cualquier código duplicado, pisado o mal implementado.

### R2. Consolidación de Reportes
Fusionar el contenido del archivo `c:\Users\gines\.gemini\antigravity\brain\97c16b6a-121d-46ae-9c8c-78b29e9f97ab\Auditoria_Reporte_Backend.md` hacia el archivo actual `backend_tech_debt_report.md`. El nuevo documento debe consolidar ambos de forma coherente y convertirse en el reporte final más actualizado.

### R3. Documentación de Errores (Modo Solo Lectura)
Documentar detalladamente en el reporte consolidado todas las fallas o malas implementaciones detectadas. **NO modificar el código fuente del proyecto backend bajo ninguna circunstancia**. El objetivo es auditar y preparar el terreno para aplicar las correcciones posteriormente.

## Acceptance Criteria

### Auditoría y Reporte
- [ ] El reporte `backend_tech_debt_report.md` integra de manera impecable toda la información del archivo `Auditoria_Reporte_Backend.md`.
- [ ] El reporte final enumera de forma explícita los errores lógicos, duplicidades de código o refactorizaciones incompletas detectadas en el análisis.
- [ ] El código fuente de la aplicación (`c:\laragon\www\Sistema_POS\pos-backend`) permanece sin modificaciones.

## 2026-09-26T22:45:00Z

> Status: Step 4-8 — Drafting Requirements & Criteria
> Goal: Craft prompt → get user approval → delegate to teamwork_preview
> Requested team: Equipo completo

Audit the existing `backend_tech_debt_report.md` against the actual Laravel codebase to verify its claims at a senior engineering level. Ensure 100% correctness by cross-referencing every claim with the source code, identify any AI hallucinations, and find any missing critical technical debt. The team must directly modify and correct the `backend_tech_debt_report.md` file to make it a 100% truthful final version.

Working directory: c:\laragon\www\Sistema_POS\pos-backend
Integrity mode: benchmark

## Requirements

### R1. Fact-Check Existing Claims
Validate every technical claim, bug, and architectural assessment in the current report against the actual code. Fix any AI hallucinations or false positives. 

### R2. Discover Missing Technical Debt
Conduct a deep inspection of the repository (controllers, services, repositories, etc.) to uncover any critical vulnerabilities, anti-patterns, or technical debt that the original report missed.

### R3. Output Generation
Modify `backend_tech_debt_report.md` directly. Add a "Corrections from Previous Version" section to explicitly state what was an hallucination and what was corrected.

## Verification Resources
- The Laravel codebase itself.
- `php artisan test` (to verify if reported bugs cause test failures or if the system actually has the tests claimed).
- Ad-hoc PHP scripts to test specific classes or behaviors mentioned in the report.

## Acceptance Criteria

### Evidence-Based Reporting
- [ ] Every technical claim or bug in the final report cites the exact file path and line number(s) as evidence.
- [ ] The report contains a dedicated section listing the specific hallucinations or errors found in the previous version.
- [ ] The team executes at least one programmatic verification (e.g., running tests, static analysis, or a scratch script) to prove the existence of a discovered or verified bug, and mentions this verification in the report.

## 2026-09-27T02:27:10Z

# Teamwork Project Prompt — Draft

> Status: Launched
> Goal: Craft prompt → get user approval → delegate to teamwork_preview
> Requested team: [none — teamwork routes from the description]

Implementar las correcciones de código correspondientes exclusivamente a la **Fase P1 (Emergencias y Seguridad Operacional)** detalladas en el documento `backend_tech_debt_report.md`. El objetivo es parchear vulnerabilidades críticas y errores fatales de base de datos sin introducir regresiones en el resto del sistema.

Working directory: c:\laragon\www\Sistema_POS\pos-backend

## Requirements

### R1. Implementación Estricta de la Fase P1
El equipo debe leer la sección de la Fase P1 del archivo `backend_tech_debt_report.md` y escribir el código para solucionar las deudas técnicas allí listadas (como el crash del ENUM de reembolsos, la subida de archivos maliciosos RCE, la exposición de datos, etc.). 
*Nota para los agentes: El [DEBT-04] (Bypass de PIN) ya fue resuelto; omitir su implementación.*

### R2. Validación y Arreglo de Tests
Todas las modificaciones deben ser validadas corriendo la suite de pruebas automatizadas del proyecto (`php artisan test`). Si algún cambio rompe un test existente, **el equipo debe iterar y arreglar el código (o el test, si quedó obsoleto por el cambio de lógica)** hasta que la suite completa pase exitosamente (100% verde).

### R3. Auditoría Adversarial
El código escrito debe ser revisado por un agente independiente para garantizar que las vulnerabilidades (ej. subida de archivos) estén realmente selladas y que no haya efectos secundarios (side-effects).

### R4. Gestión de Código (Unstaged)
Al finalizar, el equipo **NO debe realizar ningún commit en Git**. Deben dejar todos los archivos modificados guardados en el disco pero sin hacer commit (unstaged), para que el usuario pueda revisarlos manualmente en su editor.

## Acceptance Criteria

### Integridad del Sistema
- [ ] Todas las vulnerabilidades de la Fase P1 están cerradas en el código fuente.
- [ ] La suite completa de `php artisan test` corre sin errores.
- [ ] El comando `git status` muestra los archivos modificados, pero no se ha creado ningún commit nuevo en el historial.

---
*Next: when approved → delegate via invoke_subagent (see Delegation Protocol)*

