/**
 * Regression: invoice generator source must not ship real banking credentials.
 * Defaults belong in the operator's browser settings (localStorage), not git.
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

assert(
    !/\bDEFAULT_BANK_DETAILS\b/.test(source),
    "index.php does not define DEFAULT_BANK_DETAILS"
);

assert(
    !/Account Number:\s*\d{6,}/i.test(source),
    "index.php does not embed an Account Number digit string"
);

assert(
    !/Branch Code:\s*\d{4,}/i.test(source),
    "index.php does not embed a Branch Code digit string"
);

// Bank details should fall back to an empty string when settings are absent.
assert(
    /el\("bankDetails"\)\.value\s*=\s*savedSettings\.bankDetails\s*\|\|\s*""/.test(source),
    'bankDetails default falls back to "" from saved settings only'
);

if (failed > 0) {
    console.error(`\n${failed} assertion(s) failed`);
    process.exit(1);
}

console.log("\nAll assertions passed");
