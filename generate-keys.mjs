#!/usr/bin/env node
// ---------------------------------------------------------------------------
// Querymindr License Key Generator
//
// Usage:
//   node generate-keys.mjs [count]
//
// Examples:
//   node generate-keys.mjs          — generates 100 keys
//   node generate-keys.mjs 500      — generates 500 keys
//
// Output: one key per line, ready to copy-paste into WooCommerce Serial Numbers.
//
// Keep this file private — it contains the license secret.
// ---------------------------------------------------------------------------

import crypto from "crypto";

const SECRET =
  "qm-lic-v1-x7Kp2mNvR9sL4wJh8dQf6cTz3bYu1eAi5oX-musicsavvy-querymindr";

function generateKey() {
  const nonce = crypto.randomBytes(4).toString("hex").toUpperCase();
  const hmac = crypto
    .createHmac("sha256", SECRET)
    .update("qmdr:v1:" + nonce)
    .digest("hex")
    .substring(0, 8)
    .toUpperCase();
  return `QMDR-${nonce.substring(0, 4)}-${nonce.substring(4, 8)}-${hmac.substring(0, 4)}-${hmac.substring(4, 8)}`;
}

const count = parseInt(process.argv[2] ?? "100", 10);
if (isNaN(count) || count < 1 || count > 10000) {
  console.error("Usage: node generate-keys.mjs [count]  (1–10000)");
  process.exit(1);
}

for (let i = 0; i < count; i++) {
  console.log(generateKey());
}
