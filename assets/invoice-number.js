/**
 * Invoice number helpers shared by the generator UI and regression tests.
 * Kept free of DOM/browser APIs so Node can exercise the collision-avoidance logic.
 */
(function (root, factory) {
    if (typeof module === "object" && module.exports) {
        module.exports = factory();
    } else {
        root.DgkInvoiceNumber = factory();
    }
}(typeof self !== "undefined" ? self : this, function () {
    "use strict";

    function invoiceMonthPrefix(date) {
        const value = date instanceof Date ? date : new Date();
        return `DGK-${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, "0")}-`;
    }

    function nextInvoiceNumber(records, date) {
        const prefix = invoiceMonthPrefix(date);
        let max = 0;

        for (const record of records || []) {
            const number = String(record.invoiceNumber || "").trim();
            if (!number.startsWith(prefix)) continue;
            const suffix = Number(number.slice(prefix.length));
            if (Number.isInteger(suffix) && suffix > max) max = suffix;
        }

        return `${prefix}${String(max + 1).padStart(3, "0")}`;
    }

    function invoiceNumberExists(records, number) {
        const target = String(number || "").trim();
        if (!target) return false;
        return (records || []).some(
            record => String(record.invoiceNumber || "").trim() === target
        );
    }

    return {
        invoiceMonthPrefix,
        nextInvoiceNumber,
        invoiceNumberExists
    };
}));
