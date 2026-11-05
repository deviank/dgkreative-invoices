/**
 * Regression: Save PDF must render a frozen DOM snapshot, not the live preview.
 * Otherwise New/Load/edits during async html2pdf generation store the wrong PDF
 * under the invoice number captured at click time.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const indexPath = path.join(root, "index.php");
const source = fs.readFileSync(indexPath, "utf8");

let failed = 0;

function assert(condition, message) {
    if (condition) {
        console.log(`PASS  ${message}`);
        return;
    }
    console.error(`FAIL  ${message}`);
    failed += 1;
}

const savePdfMatch = source.match(/async function saveInvoicePdf\(\) \{[\s\S]*?\n        \}/);
assert(Boolean(savePdfMatch), "saveInvoicePdf function is present");

const savePdfBody = savePdfMatch ? savePdfMatch[0] : "";

assert(
    /function snapshotInvoicePreview\s*\(/.test(source),
    "snapshotInvoicePreview helper exists"
);

assert(
    /snapshotInvoicePreview\s*\(\s*el\("invoicePreview"\)\s*\)/.test(savePdfBody),
    "saveInvoicePdf snapshots #invoicePreview before rendering"
);

assert(
    /\.from\(\s*snapshot\s*\)/.test(savePdfBody),
    "html2pdf renders from the snapshot, not the live preview element"
);

assert(
    !/\.from\(\s*element\s*\)/.test(savePdfBody),
    "saveInvoicePdf does not pass a live element variable to html2pdf.from"
);

assert(
    /cloneNode\s*\(\s*true\s*\)/.test(source),
    "snapshot clones the preview with cloneNode(true)"
);

assert(
    /"newInvoice"[\s\S]*"savedInvoices"/.test(savePdfBody) && /disabled\s*=\s*true/.test(savePdfBody),
    "Save PDF disables New/Load-related controls during generation"
);

assert(
    /snapshot\.remove\(\)/.test(savePdfBody),
    "Save PDF removes the snapshot in finally"
);

if (failed > 0) {
    console.error(`\n${failed} assertion(s) failed`);
    process.exit(1);
}

console.log("\nAll assertions passed");
