// @ts-ignore — node:sqlite is experimental in Node.js v22+, stable from v22.15+
import { DatabaseSync } from "node:sqlite";
import * as fs from "fs";
import * as path from "path";
import * as os from "os";

const DB_DIR = path.join(os.homedir(), ".config", "querymindr");
export const DB_PATH = path.join(DB_DIR, "querymindr.db");

fs.mkdirSync(DB_DIR, { recursive: true });

export const db = new DatabaseSync(DB_PATH);

db.exec(`
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;

  CREATE TABLE IF NOT EXISTS file_index (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    path  TEXT    UNIQUE NOT NULL,
    name  TEXT    NOT NULL,
    extension TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    modified_at TEXT,
    created_at  TEXT,
    folder      TEXT NOT NULL,
    content_text TEXT,
    ai_summary   TEXT,
    checksum     TEXT,
    indexed_at   TEXT DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS indexing_jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    status          TEXT    NOT NULL DEFAULT 'idle',
    root_dir        TEXT,
    total_files     INTEGER NOT NULL DEFAULT 0,
    processed_files INTEGER NOT NULL DEFAULT 0,
    started_at      TEXT,
    completed_at    TEXT,
    error_message   TEXT,
    created_at      TEXT DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS kv_store (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
  );

  CREATE INDEX IF NOT EXISTS idx_file_index_checksum  ON file_index (checksum);
  CREATE INDEX IF NOT EXISTS idx_file_index_name      ON file_index (name);
  CREATE INDEX IF NOT EXISTS idx_file_index_extension ON file_index (extension);
  CREATE INDEX IF NOT EXISTS idx_file_index_folder    ON file_index (folder);
  CREATE INDEX IF NOT EXISTS idx_file_index_size      ON file_index (size_bytes);
`);

// Safe migrations — each is a no-op if the column/index already exists
try { db.exec(`ALTER TABLE file_index ADD COLUMN inode INTEGER`); } catch { }
try { db.exec(`CREATE INDEX IF NOT EXISTS idx_file_index_inode ON file_index (inode)`); } catch { }
try { db.exec(`ALTER TABLE indexing_jobs ADD COLUMN type TEXT DEFAULT 'full'`); } catch { }

// On startup, clear any jobs that were left in 'running' state from a previous
// server session — they can never resume, so mark them interrupted.
db.exec(`
  UPDATE indexing_jobs
  SET status = 'interrupted',
      error_message = 'Server was restarted while this job was running.',
      completed_at = datetime('now')
  WHERE status = 'running'
`);

export function kvGet(key: string): string | null {
  const row = db.prepare(`SELECT value FROM kv_store WHERE key = ?`).get(key) as { value: string } | undefined;
  return row?.value ?? null;
}

export function kvSet(key: string, value: string): void {
  db.prepare(`INSERT INTO kv_store (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value`).run(key, value);
}

export interface FileRow {
  id: number;
  path: string;
  name: string;
  extension: string;
  size_bytes: number;
  modified_at: string | null;
  created_at: string | null;
  folder: string;
  content_text: string | null;
  ai_summary: string | null;
  checksum: string | null;
  inode: number | null;
  indexed_at: string | null;
}

export interface JobRow {
  id: number;
  status: string;
  type: string | null;
  root_dir: string | null;
  total_files: number;
  processed_files: number;
  started_at: string | null;
  completed_at: string | null;
  error_message: string | null;
  created_at: string | null;
}
