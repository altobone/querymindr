import * as crypto from "crypto";

// ---------------------------------------------------------------------------
// Querymindr offline license key system
//
// Key format: QMDR-XXXX-XXXX-CCCC-CCCC
//   XXXX-XXXX = 8 random hex chars (32-bit nonce, ~4 billion unique keys)
//   CCCC-CCCC = 8 hex chars = first 8 chars of HMAC-SHA256(SECRET, nonce)
//
// Keys are self-validating — no server or network needed.
// The secret is embedded; any key generated with it is accepted by any
// installation, so a pool of pre-generated keys works perfectly.
// ---------------------------------------------------------------------------

const SECRET =
  "qm-lic-v1-x7Kp2mNvR9sL4wJh8dQf6cTz3bYu1eAi5oX-musicsavvy-querymindr";

const RE_KEY = /^QMDR-([0-9A-F]{4})-([0-9A-F]{4})-([0-9A-F]{4})-([0-9A-F]{4})$/i;

export function generateKey(): string {
  const nonce = crypto.randomBytes(4).toString("hex").toUpperCase();
  const hmac = crypto
    .createHmac("sha256", SECRET)
    .update("qmdr:v1:" + nonce)
    .digest("hex")
    .substring(0, 8)
    .toUpperCase();
  return `QMDR-${nonce.substring(0, 4)}-${nonce.substring(4, 8)}-${hmac.substring(0, 4)}-${hmac.substring(4, 8)}`;
}

export function validateKey(rawKey: string): boolean {
  const key = rawKey.trim().toUpperCase();
  const m = key.match(RE_KEY);
  if (!m) return false;
  const nonce = m[1] + m[2];
  const provided = m[3] + m[4];
  const expected = crypto
    .createHmac("sha256", SECRET)
    .update("qmdr:v1:" + nonce)
    .digest("hex")
    .substring(0, 8)
    .toUpperCase();
  return provided === expected;
}
