import { createRequire } from "module";
import assert from "assert/strict";

const require = createRequire(import.meta.url);
const {
    nextInvoiceNumber,
    invoiceNumberExists,
    invoiceMonthPrefix
} = require("../assets/invoice-number.js");

const august = new Date(2026, 7, 15);
let passed = 0;

function check(condition, message) {
    assert.equal(condition, true, message);
    console.log(`PASS  ${message}`);
    passed += 1;
}

check(
    invoiceMonthPrefix(august) === "DGK-2026-08-",
    "month prefix uses local year/month"
);

check(
    nextInvoiceNumber([], august) === "DGK-2026-08-001",
    "empty inventory starts at 001"
);

check(
    nextInvoiceNumber(
        [{ invoiceNumber: "DGK-2026-08-001" }, { invoiceNumber: "DGK-2026-07-009" }],
        august
    ) === "DGK-2026-08-002",
    "next number increments within the current month only"
);

check(
    nextInvoiceNumber(
        [{ invoiceNumber: "DGK-2026-08-001" }, { invoiceNumber: "DGK-2026-08-003" }],
        august
    ) === "DGK-2026-08-004",
    "next number jumps past gaps to max+1"
);

check(
    nextInvoiceNumber([{ invoiceNumber: "  DGK-2026-08-002  " }], august) === "DGK-2026-08-003",
    "trimmed invoice numbers still count toward the sequence"
);

check(
    invoiceNumberExists([{ invoiceNumber: "DGK-2026-08-001" }], "DGK-2026-08-001") === true,
    "exact invoice number is detected as existing"
);

check(
    invoiceNumberExists([{ invoiceNumber: " DGK-2026-08-001" }], "DGK-2026-08-001") === true,
    "trimmed comparison detects an existing invoice number"
);

check(
    invoiceNumberExists([{ invoiceNumber: "DGK-2026-08-001" }], "DGK-2026-08-002") === false,
    "missing invoice number is not reported as existing"
);

// The Save → New → Save data-loss scenario: after the first save, New must not
// reuse that number, so a second Save cannot silently replace the first record.
const afterFirstSave = [{ invoiceNumber: "DGK-2026-08-001", clientName: "CH Logistics" }];
const newDraftNumber = nextInvoiceNumber(afterFirstSave, august);
check(newDraftNumber === "DGK-2026-08-002", "New after Save allocates a fresh invoice number");
check(
    invoiceNumberExists(afterFirstSave, newDraftNumber) === false,
    "fresh New number does not collide with the inventory used for overwrite checks"
);

console.log(`\n${passed} passed, 0 failed`);
