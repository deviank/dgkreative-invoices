<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="DGKreative monthly service invoice generator">
    <title>DGKreative Invoice Generator</title>
    <style>
        :root {
            --ink: #17202a;
            --muted: #67727e;
            --line: #dfe4e8;
            --paper: #ffffff;
            --canvas: #f2f4f6;
            --panel: #ffffff;
            --accent: #146c63;
            --accent-dark: #0c4c46;
            --accent-soft: #e6f3f1;
            --danger: #a63a3a;
            --radius: 12px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--canvas);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
        }

        button, input, textarea, select { font: inherit; }

        .app-header {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 24px;
            color: white;
            background: var(--ink);
            border-bottom: 3px solid var(--accent);
        }

        .brand { display: flex; align-items: center; gap: 11px; }
        .brand-mark {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            color: white;
            background: var(--accent);
            border-radius: 9px;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .brand h1 { margin: 0; font-size: 17px; }
        .brand p { margin: 1px 0 0; color: #aeb7bf; font-size: 12px; }

        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
        .btn {
            min-height: 38px;
            padding: 8px 14px;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            transition: background .15s ease, border-color .15s ease;
        }
        .btn-primary { color: white; background: var(--accent); }
        .btn-primary:hover { background: var(--accent-dark); }
        .btn-secondary { color: var(--ink); background: white; border-color: var(--line); }
        .btn-secondary:hover { background: #f5f7f8; }
        .btn-danger { color: var(--danger); background: white; border-color: #e6bcbc; }
        .btn-small { min-height: 32px; padding: 5px 10px; font-size: 13px; }

        .app-shell {
            display: grid;
            grid-template-columns: minmax(360px, 480px) minmax(620px, 1fr);
            gap: 22px;
            max-width: 1500px;
            margin: 0 auto;
            padding: 22px;
            align-items: start;
        }

        .editor { display: grid; gap: 14px; }
        .form-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .form-card summary {
            padding: 14px 16px;
            cursor: pointer;
            font-weight: 800;
            list-style: none;
        }
        .form-card summary::-webkit-details-marker { display: none; }
        .form-card summary::after { content: "+"; float: right; color: var(--accent); }
        .form-card[open] summary::after { content: "−"; }
        .form-content { padding: 0 16px 16px; border-top: 1px solid var(--line); }

        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
        .field-grid .wide { grid-column: 1 / -1; }
        label { display: grid; gap: 5px; color: var(--muted); font-size: 12px; font-weight: 700; }
        input, textarea, select {
            width: 100%;
            padding: 9px 10px;
            color: var(--ink);
            background: white;
            border: 1px solid #cbd2d8;
            border-radius: 7px;
            outline: none;
        }
        input:focus, textarea:focus, select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        textarea { min-height: 70px; resize: vertical; }

        .line-editor {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 90px 92px 34px;
            gap: 7px;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }
        .line-editor:last-child { border-bottom: 0; }
        .line-editor textarea { min-height: 62px; }
        .remove-line {
            display: grid;
            width: 34px;
            height: 38px;
            place-items: center;
            color: var(--danger);
            background: #fff;
            border: 1px solid #e6bcbc;
            border-radius: 7px;
            cursor: pointer;
        }
        .add-row { margin-top: 12px; }
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 14px;
        }
        .toggle-row input { width: auto; }

        .preview-wrap {
            position: sticky;
            top: 90px;
            overflow: auto;
            padding-bottom: 12px;
        }

        .invoice {
            width: 100%;
            min-height: 297mm;
            padding: 18mm;
            background: var(--paper);
            border: 1px solid var(--line);
            box-shadow: 0 8px 28px rgba(23, 32, 42, .09);
        }
        .invoice-head {
            display: flex;
            justify-content: space-between;
            gap: 28px;
            padding-bottom: 22px;
            border-bottom: 3px solid var(--accent);
        }
        .invoice-brand { display: flex; align-items: center; }
        .invoice-logo {
            display: block;
            width: 205px;
            height: auto;
            margin-bottom: 7px;
        }
        .invoice-brand-tag { color: var(--muted); font-size: 12px; }
        .invoice-title { text-align: right; }
        .invoice-title h2 { margin: 0 0 5px; font-size: 28px; letter-spacing: 2px; }
        .invoice-title strong { color: var(--accent); font-size: 14px; }

        .address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 35px;
            padding: 25px 0;
        }
        .eyebrow {
            margin-bottom: 7px;
            color: var(--accent);
            font-size: 10px;
            font-weight: 850;
            letter-spacing: 1.3px;
        }
        .address-block strong { display: block; margin-bottom: 3px; font-size: 15px; }
        .address-block div { color: var(--muted); font-size: 12px; white-space: pre-line; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            margin-bottom: 24px;
            background: #f6f8f9;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        .meta-item { padding: 11px 13px; border-right: 1px solid var(--line); }
        .meta-item:last-child { border-right: 0; }
        .meta-item span { display: block; color: var(--muted); font-size: 10px; font-weight: 750; }
        .meta-item strong { font-size: 12px; }

        .invoice-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .invoice-table th {
            padding: 10px 8px;
            color: white;
            background: var(--ink);
            font-size: 10px;
            letter-spacing: .6px;
            text-align: left;
        }
        .invoice-table th:nth-child(n+2), .invoice-table td:nth-child(n+2) { text-align: right; }
        .invoice-table th:nth-child(2) { width: 12%; }
        .invoice-table th:nth-child(3), .invoice-table th:nth-child(4) { width: 17%; }
        .invoice-table td {
            padding: 13px 8px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 12px;
        }
        .item-name { font-weight: 750; white-space: pre-line; }

        .totals-area {
            display: grid;
            grid-template-columns: 1fr 250px;
            gap: 28px;
            padding-top: 20px;
        }
        .notes { color: var(--muted); font-size: 11px; white-space: pre-line; }
        .total-row { display: flex; justify-content: space-between; gap: 18px; padding: 6px 0; font-size: 12px; }
        .grand-total {
            margin-top: 4px;
            padding-top: 10px;
            color: var(--accent-dark);
            border-top: 2px solid var(--accent);
            font-size: 17px;
            font-weight: 850;
        }

        .payment-box {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 26px;
            margin-top: 32px;
            padding: 16px;
            background: var(--accent-soft);
            border-radius: 8px;
        }
        .payment-box strong { display: block; margin-bottom: 5px; font-size: 12px; }
        .payment-box div { color: #315b57; font-size: 11px; white-space: pre-line; }

        .invoice-footer {
            margin-top: 38px;
            padding-top: 12px;
            color: var(--muted);
            border-top: 1px solid var(--line);
            font-size: 10px;
            text-align: center;
        }

        .saved-card { padding: 16px; background: white; border: 1px solid var(--line); border-radius: var(--radius); }
        .saved-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .status { min-height: 20px; margin-top: 8px; color: var(--accent-dark); font-size: 12px; }

        @media (max-width: 1050px) {
            .app-shell { grid-template-columns: 1fr; }
            .preview-wrap { position: static; }
            .invoice { min-width: 700px; }
        }

        @media (max-width: 650px) {
            .app-header { align-items: flex-start; padding: 12px; }
            .brand p { display: none; }
            .app-shell { padding: 12px; }
            .field-grid { grid-template-columns: 1fr; }
            .field-grid .wide { grid-column: auto; }
            .line-editor { grid-template-columns: 1fr 75px 85px 34px; }
        }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: white; }
            .app-header, .editor { display: none !important; }
            .app-shell { display: block; max-width: none; padding: 0; }
            .preview-wrap { position: static; overflow: visible; padding: 0; }
            .invoice {
                width: 210mm;
                min-height: 297mm;
                padding: 15mm 17mm;
                border: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="brand">
            <div class="brand-mark">DG</div>
            <div>
                <h1>Invoice Generator</h1>
                <p>DGKreative monthly services</p>
            </div>
        </div>
        <div class="toolbar">
            <button class="btn btn-secondary" type="button" id="newInvoice">New</button>
            <button class="btn btn-secondary" type="button" id="saveInvoice">Save</button>
            <button class="btn btn-primary" type="button" id="printInvoice">Print / Save PDF</button>
        </div>
    </header>

    <main class="app-shell">
        <section class="editor" aria-label="Invoice editor">
            <details class="form-card" open>
                <summary>Invoice details</summary>
                <div class="form-content">
                    <div class="field-grid">
                        <label>Invoice number
                            <input id="invoiceNumber" type="text">
                        </label>
                        <label>Billing period
                            <input id="billingPeriod" type="month">
                        </label>
                        <label>Invoice date
                            <input id="invoiceDate" type="date">
                        </label>
                        <label>Due date
                            <input id="dueDate" type="date">
                        </label>
                    </div>
                </div>
            </details>

            <details class="form-card">
                <summary>DGKreative details</summary>
                <div class="form-content">
                    <div class="field-grid">
                        <label>Business name
                            <input id="sellerName" value="DGKreative">
                        </label>
                        <label>Email
                            <input id="sellerEmail" type="email" placeholder="accounts@dgkreative.co.za">
                        </label>
                        <label class="wide">Address
                            <textarea id="sellerAddress" placeholder="Your business address"></textarea>
                        </label>
                        <label>Phone
                            <input id="sellerPhone" placeholder="+27 ...">
                        </label>
                        <label>Registration / reference
                            <input id="sellerRegistration" placeholder="Optional">
                        </label>
                    </div>
                </div>
            </details>

            <details class="form-card" open>
                <summary>Customer</summary>
                <div class="form-content">
                    <div class="field-grid">
                        <label>Customer name
                            <input id="clientName" value="CH Logistics">
                        </label>
                        <label>Email
                            <input id="clientEmail" type="email" placeholder="Customer email">
                        </label>
                        <label class="wide">Address
                            <textarea id="clientAddress" placeholder="Customer billing address"></textarea>
                        </label>
                        <label class="wide">Website / reference
                            <input id="clientReference" value="chlog.co.za">
                        </label>
                    </div>
                </div>
            </details>

            <details class="form-card" open>
                <summary>Services</summary>
                <div class="form-content">
                    <div id="lineEditors"></div>
                    <div class="add-row">
                        <button class="btn btn-secondary btn-small" id="addLine" type="button">Add line item</button>
                    </div>
                    <div class="toggle-row">
                        <label style="display:flex;grid-template-columns:auto 1fr;align-items:center;">
                            <input id="vatEnabled" type="checkbox">
                            Charge 15% VAT
                        </label>
                        <strong id="editorTotal">R999.00</strong>
                    </div>
                </div>
            </details>

            <details class="form-card">
                <summary>Payment and notes</summary>
                <div class="form-content">
                    <div class="field-grid">
                        <label class="wide">Banking details
                            <textarea id="bankDetails" placeholder="Bank:&#10;Account name: DGKreative&#10;Account number:&#10;Branch code:"></textarea>
                        </label>
                        <label class="wide">Payment reference
                            <input id="paymentReference" placeholder="Use invoice number">
                        </label>
                        <label class="wide">Invoice notes
                            <textarea id="notes">Includes routine maintenance and up to one hour of minor content or technical changes. Additional work is quoted separately. Domain renewal is billed annually when due.</textarea>
                        </label>
                    </div>
                </div>
            </details>

            <section class="saved-card">
                <strong>Saved invoices</strong>
                <div class="saved-row" style="margin-top:10px;">
                    <select id="savedInvoices" aria-label="Saved invoices">
                        <option value="">Select a saved invoice</option>
                    </select>
                    <button class="btn btn-danger btn-small" id="deleteInvoice" type="button">Delete</button>
                </div>
                <div class="status" id="status" role="status"></div>
            </section>
        </section>

        <section class="preview-wrap" aria-label="Invoice preview">
            <article class="invoice" id="invoicePreview">
                <header class="invoice-head">
                    <div class="invoice-brand">
                        <div>
                            <img class="invoice-logo" src="assets/dgkreative-logo.png" alt="DGKreative">
                            <div class="invoice-brand-tag">Digital services, hosting and support</div>
                        </div>
                    </div>
                    <div class="invoice-title">
                        <h2>INVOICE</h2>
                        <strong data-preview="invoiceNumber"></strong>
                    </div>
                </header>

                <section class="address-grid">
                    <div class="address-block">
                        <div class="eyebrow">FROM</div>
                        <strong data-preview="sellerName">DGKreative</strong>
                        <div id="sellerAddressPreview"></div>
                    </div>
                    <div class="address-block">
                        <div class="eyebrow">BILL TO</div>
                        <strong data-preview="clientName">CH Logistics</strong>
                        <div id="clientAddressPreview"></div>
                    </div>
                </section>

                <section class="meta-grid">
                    <div class="meta-item">
                        <span>INVOICE DATE</span>
                        <strong id="invoiceDatePreview"></strong>
                    </div>
                    <div class="meta-item">
                        <span>DUE DATE</span>
                        <strong id="dueDatePreview"></strong>
                    </div>
                    <div class="meta-item">
                        <span>BILLING PERIOD</span>
                        <strong id="billingPeriodPreview"></strong>
                    </div>
                </section>

                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody id="lineItemsPreview"></tbody>
                </table>

                <section class="totals-area">
                    <div>
                        <div class="eyebrow">NOTES</div>
                        <div class="notes" id="notesPreview"></div>
                    </div>
                    <div>
                        <div class="total-row"><span>Subtotal</span><strong id="subtotalPreview"></strong></div>
                        <div class="total-row" id="vatRow"><span>VAT (15%)</span><strong id="vatPreview"></strong></div>
                        <div class="total-row grand-total"><span>Total due</span><span id="totalPreview"></span></div>
                    </div>
                </section>

                <section class="payment-box">
                    <div>
                        <strong>Payment details</strong>
                        <div id="bankDetailsPreview">Add banking details in the editor.</div>
                    </div>
                    <div>
                        <strong>Payment reference</strong>
                        <div id="paymentReferencePreview"></div>
                    </div>
                </section>

                <footer class="invoice-footer" id="invoiceFooter"></footer>
            </article>
        </section>
    </main>

    <script>
        "use strict";

        const STORAGE_KEY = "dgkreative_invoice_generator_v1";
        const SETTINGS_KEY = "dgkreative_invoice_settings_v1";
        const formIds = [
            "invoiceNumber", "billingPeriod", "invoiceDate", "dueDate",
            "sellerName", "sellerEmail", "sellerAddress", "sellerPhone", "sellerRegistration",
            "clientName", "clientEmail", "clientAddress", "clientReference",
            "vatEnabled", "bankDetails", "paymentReference", "notes"
        ];

        let lines = [];

        const el = id => document.getElementById(id);
        const currency = value => new Intl.NumberFormat("en-ZA", {
            style: "currency",
            currency: "ZAR"
        }).format(Number(value) || 0);

        function dateInputValue(date) {
            const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
            return local.toISOString().slice(0, 10);
        }

        function formatDate(value) {
            if (!value) return "—";
            return new Intl.DateTimeFormat("en-ZA", {
                day: "2-digit", month: "short", year: "numeric"
            }).format(new Date(value + "T12:00:00"));
        }

        function formatMonth(value) {
            if (!value) return "—";
            const [year, month] = value.split("-").map(Number);
            return new Intl.DateTimeFormat("en-ZA", {
                month: "long", year: "numeric"
            }).format(new Date(year, month - 1, 1));
        }

        function defaultInvoiceNumber() {
            const now = new Date();
            return `DGK-${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-001`;
        }

        function defaultLines() {
            return [
                {
                    description: "Managed website hosting — chlog.co.za\nHosting administration, SSL oversight, backups and uptime checks",
                    quantity: 1,
                    rate: 249
                },
                {
                    description: "Website maintenance and support\nSoftware updates, security review, backup verification and up to 1 hour of minor changes",
                    quantity: 1,
                    rate: 750
                }
            ];
        }

        function initialiseDefaults(preserveBusiness = true) {
            const savedSettings = preserveBusiness ? loadJson(SETTINGS_KEY, {}) : {};
            const today = new Date();
            const due = new Date(today);
            due.setDate(due.getDate() + 7);

            formIds.forEach(id => {
                const field = el(id);
                if (field.type === "checkbox") field.checked = false;
                else field.value = "";
            });

            el("invoiceNumber").value = defaultInvoiceNumber();
            el("invoiceDate").value = dateInputValue(today);
            el("dueDate").value = dateInputValue(due);
            el("billingPeriod").value = dateInputValue(today).slice(0, 7);
            el("sellerName").value = savedSettings.sellerName || "DGKreative";
            el("sellerEmail").value = savedSettings.sellerEmail || "";
            el("sellerAddress").value = savedSettings.sellerAddress || "";
            el("sellerPhone").value = savedSettings.sellerPhone || "";
            el("sellerRegistration").value = savedSettings.sellerRegistration || "";
            el("bankDetails").value = savedSettings.bankDetails || "";
            el("clientName").value = "CH Logistics";
            el("clientReference").value = "chlog.co.za";
            el("notes").value = "Includes routine maintenance and up to one hour of minor content or technical changes. Additional work is quoted separately. Domain renewal is billed annually when due.";
            lines = defaultLines();
            renderLineEditors();
            updatePreview();
            setStatus("New invoice ready.");
        }

        function loadJson(key, fallback) {
            try {
                return JSON.parse(localStorage.getItem(key)) ?? fallback;
            } catch {
                return fallback;
            }
        }

        function setStatus(message) {
            el("status").textContent = message;
            window.clearTimeout(setStatus.timer);
            setStatus.timer = window.setTimeout(() => {
                el("status").textContent = "";
            }, 3500);
        }

        function renderLineEditors() {
            const container = el("lineEditors");
            container.replaceChildren();

            lines.forEach((line, index) => {
                const row = document.createElement("div");
                row.className = "line-editor";

                const description = document.createElement("textarea");
                description.value = line.description;
                description.setAttribute("aria-label", `Line ${index + 1} description`);
                description.addEventListener("input", event => {
                    lines[index].description = event.target.value;
                    updatePreview();
                });

                const quantity = document.createElement("input");
                quantity.type = "number";
                quantity.min = "0";
                quantity.step = "0.01";
                quantity.value = line.quantity;
                quantity.setAttribute("aria-label", `Line ${index + 1} quantity`);
                quantity.addEventListener("input", event => {
                    lines[index].quantity = Number(event.target.value);
                    updatePreview();
                });

                const rate = document.createElement("input");
                rate.type = "number";
                rate.min = "0";
                rate.step = "0.01";
                rate.value = line.rate;
                rate.setAttribute("aria-label", `Line ${index + 1} rate`);
                rate.addEventListener("input", event => {
                    lines[index].rate = Number(event.target.value);
                    updatePreview();
                });

                const remove = document.createElement("button");
                remove.type = "button";
                remove.className = "remove-line";
                remove.textContent = "×";
                remove.title = "Remove line item";
                remove.addEventListener("click", () => {
                    if (lines.length === 1) return;
                    lines.splice(index, 1);
                    renderLineEditors();
                    updatePreview();
                });

                row.append(description, quantity, rate, remove);
                container.appendChild(row);
            });
        }

        function getSubtotal() {
            return lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.rate) || 0), 0);
        }

        function updateAddressPreview(prefix) {
            const parts = [
                el(`${prefix}Address`).value,
                el(`${prefix}Email`).value,
                prefix === "seller" ? el("sellerPhone").value : "",
                prefix === "seller" ? el("sellerRegistration").value : el("clientReference").value
            ].filter(Boolean);
            el(`${prefix}AddressPreview`).textContent = parts.length ? parts.join("\n") : "Details to be completed";
        }

        function updatePreview() {
            document.querySelectorAll("[data-preview]").forEach(node => {
                node.textContent = el(node.dataset.preview).value || "—";
            });

            updateAddressPreview("seller");
            updateAddressPreview("client");
            el("invoiceDatePreview").textContent = formatDate(el("invoiceDate").value);
            el("dueDatePreview").textContent = formatDate(el("dueDate").value);
            el("billingPeriodPreview").textContent = formatMonth(el("billingPeriod").value);
            el("notesPreview").textContent = el("notes").value || "Thank you for your business.";
            el("bankDetailsPreview").textContent = el("bankDetails").value || "Add banking details in the editor.";
            el("paymentReferencePreview").textContent = el("paymentReference").value || el("invoiceNumber").value;

            const tbody = el("lineItemsPreview");
            tbody.replaceChildren();
            lines.forEach(line => {
                const row = document.createElement("tr");
                const description = document.createElement("td");
                description.className = "item-name";
                description.textContent = line.description || "Service";
                const quantity = document.createElement("td");
                quantity.textContent = Number(line.quantity || 0).toLocaleString("en-ZA");
                const rate = document.createElement("td");
                rate.textContent = currency(line.rate);
                const amount = document.createElement("td");
                amount.textContent = currency((Number(line.quantity) || 0) * (Number(line.rate) || 0));
                row.append(description, quantity, rate, amount);
                tbody.appendChild(row);
            });

            const subtotal = getSubtotal();
            const vat = el("vatEnabled").checked ? subtotal * 0.15 : 0;
            el("subtotalPreview").textContent = currency(subtotal);
            el("vatPreview").textContent = currency(vat);
            el("totalPreview").textContent = currency(subtotal + vat);
            el("editorTotal").textContent = currency(subtotal + vat);
            el("vatRow").style.display = el("vatEnabled").checked ? "flex" : "none";

            const footerParts = [
                el("sellerName").value,
                el("sellerEmail").value,
                el("sellerPhone").value
            ].filter(Boolean);
            el("invoiceFooter").textContent = footerParts.join("  •  ");
        }

        function collectInvoice() {
            const data = { lines: structuredClone(lines), savedAt: new Date().toISOString() };
            formIds.forEach(id => {
                const field = el(id);
                data[id] = field.type === "checkbox" ? field.checked : field.value;
            });
            return data;
        }

        function populateInvoice(data) {
            formIds.forEach(id => {
                if (!(id in data)) return;
                const field = el(id);
                if (field.type === "checkbox") field.checked = Boolean(data[id]);
                else field.value = data[id] ?? "";
            });
            lines = Array.isArray(data.lines) && data.lines.length ? data.lines : defaultLines();
            renderLineEditors();
            updatePreview();
        }

        function saveCurrentInvoice() {
            const number = el("invoiceNumber").value.trim();
            if (!number) {
                setStatus("Add an invoice number before saving.");
                el("invoiceNumber").focus();
                return;
            }

            const invoices = loadJson(STORAGE_KEY, {});
            invoices[number] = collectInvoice();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(invoices));

            const settings = {};
            ["sellerName", "sellerEmail", "sellerAddress", "sellerPhone", "sellerRegistration", "bankDetails"].forEach(id => {
                settings[id] = el(id).value;
            });
            localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));

            refreshSavedInvoices(number);
            setStatus(`Saved ${number} in this browser.`);
        }

        function refreshSavedInvoices(selected = "") {
            const invoices = loadJson(STORAGE_KEY, {});
            const select = el("savedInvoices");
            select.replaceChildren(new Option("Select a saved invoice", ""));

            Object.keys(invoices).sort().reverse().forEach(number => {
                select.add(new Option(number, number));
            });
            select.value = selected;
        }

        function loadSavedInvoice(number) {
            if (!number) return;
            const invoices = loadJson(STORAGE_KEY, {});
            if (!invoices[number]) return;
            populateInvoice(invoices[number]);
            setStatus(`Loaded ${number}.`);
        }

        function deleteSavedInvoice() {
            const number = el("savedInvoices").value;
            if (!number) {
                setStatus("Select an invoice to delete.");
                return;
            }
            if (!window.confirm(`Delete saved invoice ${number}?`)) return;
            const invoices = loadJson(STORAGE_KEY, {});
            delete invoices[number];
            localStorage.setItem(STORAGE_KEY, JSON.stringify(invoices));
            refreshSavedInvoices();
            setStatus(`Deleted ${number}.`);
        }

        formIds.forEach(id => el(id).addEventListener("input", updatePreview));

        el("addLine").addEventListener("click", () => {
            lines.push({ description: "Additional service", quantity: 1, rate: 0 });
            renderLineEditors();
            updatePreview();
        });
        el("newInvoice").addEventListener("click", () => initialiseDefaults(true));
        el("saveInvoice").addEventListener("click", saveCurrentInvoice);
        el("printInvoice").addEventListener("click", () => {
            updatePreview();
            window.print();
        });
        el("savedInvoices").addEventListener("change", event => loadSavedInvoice(event.target.value));
        el("deleteInvoice").addEventListener("click", deleteSavedInvoice);

        initialiseDefaults(true);
        refreshSavedInvoices();
    </script>
</body>
</html>
