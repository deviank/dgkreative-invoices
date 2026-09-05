/**
 * Regression: a poison legacy localStorage invoice must not prevent listing
 * server records. Migration should skip unsavable entries and still clear legacy storage.
 */

const API = process.env.DGK_API_BASE || "http://127.0.0.1:8765/index.php";
const LEGACY_STORAGE_KEY = "dgkreative_invoice_generator_v1";

let passed = 0;
let failed = 0;

function assertTrue(condition, message) {
  if (condition) {
    console.log(`PASS  ${message}`);
    passed++;
  } else {
    console.log(`FAIL  ${message}`);
    failed++;
  }
}

async function requestApi(action, options = {}) {
  const response = await fetch(`${API}?api=${action}`, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("Invoice storage returned an invalid response.");
  }
  if (!response.ok || !result.ok) {
    throw new Error(result.error || "Invoice storage request failed.");
  }
  return result;
}

/** Mirrors the fixed migrateBrowserInvoices control flow against a fake localStorage. */
async function migrateBrowserInvoices(localStorage, setStatus = () => {}) {
  const raw = localStorage.getItem(LEGACY_STORAGE_KEY);
  const legacyInvoices = raw ? JSON.parse(raw) : {};
  const entries = Object.values(legacyInvoices);
  if (!entries.length) return;

  let migrated = 0;
  for (const invoice of entries) {
    try {
      await requestApi("save", {
        method: "POST",
        body: JSON.stringify(invoice),
      });
      migrated += 1;
    } catch {
      // skip poison
    }
  }

  localStorage.removeItem(LEGACY_STORAGE_KEY);
  if (migrated > 0) {
    setStatus(`${migrated} browser invoice record(s) moved into the records folder.`);
  }
}

/** Mirrors the fixed refreshSavedInvoices control flow. */
async function refreshSavedInvoices(localStorage, migrate = true) {
  let records = null;
  let migrateError = null;

  if (migrate) {
    try {
      await migrateBrowserInvoices(localStorage);
    } catch (error) {
      migrateError = error;
    }
  }

  // List must still run after migrate failure/skip.
  const result = await requestApi("list");
  records = result.records;
  return { records, migrateError, legacy: localStorage.getItem(LEGACY_STORAGE_KEY) };
}

function memoryLocalStorage(seed = {}) {
  const store = { ...seed };
  return {
    getItem: (key) => (key in store ? store[key] : null),
    setItem: (key, value) => {
      store[key] = String(value);
    },
    removeItem: (key) => {
      delete store[key];
    },
  };
}

// --- Unit-level: poison entry alone must clear legacy and not throw ---
{
  const calls = [];
  const fakeRequest = async () => {
    throw new Error("A valid invoice number is required.");
  };

  // Inline the same skip/clear contract without network
  async function migrateWithFakeSave(localStorage) {
    const raw = localStorage.getItem(LEGACY_STORAGE_KEY);
    const entries = Object.values(JSON.parse(raw));
    let migrated = 0;
    for (const invoice of entries) {
      try {
        await fakeRequest("save", invoice);
        migrated += 1;
      } catch (error) {
        calls.push(error.message);
      }
    }
    localStorage.removeItem(LEGACY_STORAGE_KEY);
    return migrated;
  }

  const ls = memoryLocalStorage({
    [LEGACY_STORAGE_KEY]: JSON.stringify({
      poison: { invoiceNumber: "!!!", clientName: "Poison" },
    }),
  });
  const migrated = await migrateWithFakeSave(ls);
  assertTrue(migrated === 0, "poison-only migration migrates zero records");
  assertTrue(ls.getItem(LEGACY_STORAGE_KEY) === null, "poison migration clears legacy localStorage");
  assertTrue(calls.length === 1, "poison entry is attempted once then skipped");
}

// --- Integration: server list remains reachable with poison legacy data ---
{
  // Seed a real invoice
  await requestApi("save", {
    method: "POST",
    body: JSON.stringify({
      invoiceNumber: "DGK-2026-09-001",
      clientName: "CH Logistics",
      lines: [{ description: "Hosting", quantity: 1, rate: 249 }],
    }),
  });

  const ls = memoryLocalStorage({
    [LEGACY_STORAGE_KEY]: JSON.stringify({
      poison: { invoiceNumber: "!!!", clientName: "Poison" },
      good: {
        invoiceNumber: "DGK-2026-08-001",
        clientName: "Legacy Client",
        lines: [{ description: "Old", quantity: 1, rate: 10 }],
      },
    }),
  });

  const { records, migrateError, legacy } = await refreshSavedInvoices(ls, true);

  assertTrue(migrateError === null, "migration does not throw out to refreshSavedInvoices");
  assertTrue(legacy === null, "legacy localStorage cleared after mixed migrate attempt");
  assertTrue(
    Array.isArray(records) && records.some((r) => r.invoiceNumber === "DGK-2026-09-001"),
    "server invoice list still includes pre-existing DGK-2026-09-001"
  );
  assertTrue(
    records.some((r) => r.invoiceNumber === "DGK-2026-08-001"),
    "valid legacy invoice was migrated alongside poison skip"
  );
  assertTrue(
    !records.some((r) => r.clientName === "Poison"),
    "poison invoice was not saved to the server"
  );
}

// --- Broken prior contract demonstration helper (documentation assert) ---
{
  // Old behavior: first failure aborts before list
  let listReached = false;
  try {
    await (async () => {
      await requestApi("save", {
        method: "POST",
        body: JSON.stringify({ invoiceNumber: "!!!", clientName: "Poison" }),
      });
      listReached = true;
      await requestApi("list");
    })();
  } catch (error) {
    assertTrue(
      error.message.includes("valid invoice number") && listReached === false,
      "pre-fix control flow would abort before list on poison save"
    );
  }
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
