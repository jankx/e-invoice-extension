<?php
namespace Jankx\Extensions\EInvoice\Render;

/**
 * A4 print stylesheet for invoice documents.
 *
 * Kept in one place so the on-screen preview, the browser print dialog and the
 * Dompdf output all lay out identically — a PDF that looks different from the
 * preview is confusing when a customer compares them.
 *
 * Rules that matter for a legal document:
 *   • the table header repeats on page breaks (`thead { display: table-header-group }`)
 *   • rows are not split across pages
 *   • totals and the signature block stay together
 *
 * @package Jankx\Extensions\EInvoice\Render
 */
trait InvoicePrintStyles
{
    protected function css(): string
    {
        return <<<'CSS'
:root {
    --je-ink: #1a1a1a;
    --je-muted: #6b7280;
    --je-line: #d4d4d8;
    --je-bg: #ffffff;
    --je-accent: #111827;
    --je-soft: #f8fafc;
}

* { box-sizing: border-box; }

body.jankx-einvoice {
    margin: 0;
    padding: 24px;
    background: #eef1f5;
    color: var(--je-ink);
    font-family: "DejaVu Sans", "Be My Text", Roboto, Helvetica, Arial, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.je-sheet {
    max-width: 210mm;
    margin: 0 auto;
    padding: 16mm 14mm;
    background: var(--je-bg);
    box-shadow: 0 1px 3px rgba(0, 0, 0, .12);
}

.je-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    border-bottom: 2px solid var(--je-accent);
    padding-bottom: 12px;
}

.je-title { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.01em; }
.je-title-sub { margin: 2px 0 0; font-size: 12px; color: var(--je-muted); }

.je-number {
    text-align: right;
    white-space: nowrap;
    font-size: 13px;
}
.je-number dt { color: var(--je-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.je-number dd { margin: 0 0 4px; font-weight: 600; font-size: 15px; font-variant-numeric: tabular-nums; }

.je-parties {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    padding: 16px 0;
    border-bottom: 1px solid var(--je-line);
}
.je-party { flex: 1 1 260px; min-width: 240px; }
.je-party-label {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--je-muted);
}
.je-party-name { margin: 0 0 4px; font-weight: 700; font-size: 14px; }
.je-party-lines { margin: 0; }
.je-party-lines div { display: flex; gap: 8px; padding: 1px 0; }
.je-party-lines dt { flex: 0 0 96px; color: var(--je-muted); }
.je-party-lines dd { margin: 0; flex: 1 1 auto; }

.je-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
.je-table thead { display: table-header-group; }
.je-table th, .je-table td { padding: 7px 8px; border-bottom: 1px solid var(--je-line); vertical-align: top; }
.je-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--je-muted);
    text-align: left;
    border-bottom: 1.5px solid var(--je-accent);
}
.je-table tr { page-break-inside: avoid; }
.je-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.je-center { text-align: center; }
.je-index { color: var(--je-muted); }

.je-summary {
    display: flex;
    justify-content: flex-end;
    margin-top: 14px;
    page-break-inside: avoid;
}
.je-summary-inner { min-width: 320px; }
.je-summary table { width: 100%; border-collapse: collapse; }
.je-summary th { text-align: left; padding: 5px 8px; color: var(--je-muted); font-weight: 500; }
.je-summary td { text-align: right; padding: 5px 8px; font-variant-numeric: tabular-nums; }
.je-summary .je-rule td { border-top: 1px solid var(--je-line); }
.je-summary .je-total th { color: var(--je-ink); font-weight: 700; text-transform: uppercase; font-size: 12px; }
.je-summary .je-total td {
    font-weight: 700;
    font-size: 16px;
    border-top: 2px solid var(--je-accent);
    border-bottom: 2px solid var(--je-accent);
}

.je-tax { margin-top: 16px; page-break-inside: avoid; }
.je-tax h3 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--je-muted); }
.je-tax table { width: 100%; max-width: 480px; border-collapse: collapse; }
.je-tax th, .je-tax td { padding: 4px 8px; border-bottom: 1px solid var(--je-line); }
.je-tax th { text-align: left; font-weight: 500; color: var(--je-muted); }
.je-tax td { text-align: right; font-variant-numeric: tabular-nums; }

.je-words {
    margin-top: 16px;
    padding: 10px 12px;
    background: var(--je-soft);
    border-left: 3px solid var(--je-accent);
    page-break-inside: avoid;
}
.je-words-label { margin: 0; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--je-muted); }
.je-words-value { margin: 2px 0 0; font-weight: 600; font-style: italic; }

.je-sign { display: flex; justify-content: flex-end; margin-top: 28px; page-break-inside: avoid; }
.je-sign-inner { width: 280px; text-align: center; }
.je-sign-role { font-weight: 700; }
.je-sign-note { color: var(--je-muted); font-size: 11px; }
.je-sign-space { height: 92px; }
.je-sign-name { font-weight: 600; border-top: 1px solid var(--je-ink); padding-top: 4px; display: inline-block; min-width: 200px; }

.je-foot {
    margin-top: 24px;
    padding-top: 10px;
    border-top: 1px solid var(--je-line);
    color: var(--je-muted);
    font-size: 11px;
    text-align: center;
}

.je-actions { margin: 0 0 16px; display: flex; gap: 8px; justify-content: flex-end; }
.je-btn {
    display: inline-block;
    padding: 7px 14px;
    border: 1px solid var(--je-accent);
    border-radius: 6px;
    background: var(--je-accent);
    color: #fff;
    font-size: 13px;
    text-decoration: none;
    cursor: pointer;
}
.je-btn--ghost { background: transparent; color: var(--je-accent); }

@media print {
    body.jankx-einvoice { background: #fff; padding: 0; font-size: 11.5px; }
    .je-sheet { box-shadow: none; max-width: none; padding: 0; }
    .je-actions { display: none !important; }
    @page { size: A4; margin: 12mm; }
}

@media (max-width: 640px) {
    body.jankx-einvoice { padding: 10px; }
    .je-sheet { padding: 16px; }
    .je-head { flex-direction: column; gap: 10px; }
    .je-number { text-align: left; }
    .je-summary-inner { min-width: 0; width: 100%; }
}
CSS;
    }
}