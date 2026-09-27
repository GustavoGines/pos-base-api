# DISPATCH

## 2026-09-27T04:19:31Z

You are the Project Orchestrator for Phase P2 of Sistema POS Backend.

Your Working Directory is: C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\orchestrator_4
Project Root: C:\laragon\www\Sistema_POS\pos-backend
Authoritative Request: C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\ORIGINAL_REQUEST.md (see header ## 2026-09-27T04:06:07Z)
Authoritative Report: C:\laragon\www\Sistema_POS\pos-backend\backend_tech_debt_report.md (see Section "Fase P2: Alta Prioridad / Concurrencia, DRY y Precios (Semana 1)")

MISSION & OBJECTIVES:
Implement exclusively the code fixes corresponding to Phase P2 (Alta Prioridad: Concurrencia, DRY y Precios) from backend_tech_debt_report.md:
1. P2.1 - DeliveryNoteController.php:91-125: Shield delivery notes to prevent double stock deduction. Validate if origin sale already deducted inventory before deducting stock, and wrap in DB::transaction.
2. P2.2 - SaleService::processItems(): Reconcile unit prices and subtotals. Allow explicit agreed unit price to prevail or recalculate subtotal atomically (unit_price * quantity == subtotal).
3. P2.3 - Synchronize StockController and AdjustStockRequest: Inject AdjustStockRequest in StockController::adjust(), expand valid types to 'in,out,increment,decrement', and delete dead method ProductController::adjustStock(). Update tests calling this accordingly.
4. P2.4 - Unify Excel Exports with Repository: Refactor ProfitByCategoryExport and MonthlyBalanceExport to consume SalesAnalyticsRepository, excluding internal accounts and deducting cash expenses.
5. P2.5 - Connect Native Laravel Auth: In ValidateSessionToken.php:47, add \Illuminate\Support\Facades\Auth::setUser($user) so auth()->user() is always populated across the application.
6. P2.6 - Resolve Race Condition in Quotes: Wrap Quote::nextQuoteNumber() in a transaction with locking (or atomic sequence generation) to prevent duplicate quote numbers under concurrency.

CRITICAL REQUIREMENTS:
- R1. Strict Implementation of Phase P2 items only. Do not touch unrelated features. Note that Phase P1 fixes are already on disk (unstaged) - preserve them and keep test suite passing.
- R2. Test Validation & Suite 100% Green: Validate with `php artisan test`. Update obsolete tests (such as AdjustStockRequest injection expectations) so the entire test suite passes 100% green.
- R3. Adversarial Review: Have an independent subagent reviewer/challenger audit the code changes, especially verifying deadlock prevention and race condition elimination in Quotes and DeliveryNotes.
- R4. Code Management (UNSTAGED): DO NOT RUN `git commit`! Leave all modified and created files saved on disk in an unstaged state so the user can inspect them manually.

COMMUNICATION & LIFECYCLE:
- Maintain your own BRIEFING.md, plan.md, and progress.md in your working directory C:\laragon\www\Sistema_POS\pos-backend\.agents\teamwork\orchestrator_4.
- Update progress.md frequently so sentinel crons can track progress and liveness.
- When all criteria are met, write handoff.md and send a completion message back to the sentinel.
