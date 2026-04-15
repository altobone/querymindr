import { Router, IRouter, Request, Response } from "express";
import { exec } from "child_process";
import { promisify } from "util";
import * as fs from "fs";
import * as path from "path";
import * as crypto from "crypto";
import * as os from "os";
import Anthropic from "@anthropic-ai/sdk";
import fg from "fast-glob";
import Fuse from "fuse.js";
import mime from "mime-types";
import { db, FileRow, JobRow } from "../lib/file-finder-db";

const router: IRouter = Router();
const execAsync = promisify(exec);

const ROOT_DIR = process.env.ROOT_DIR || process.cwd();
const VALID_MODELS = ["claude-haiku-4-5", "claude-sonnet-4-5"];
const DEFAULT_MODEL = "claude-haiku-4-5";

const CONFIG_DIR = path.join(os.homedir(), ".config", "file-finder");
const CONFIG_FILE = path.join(CONFIG_DIR, "config.json");

interface AppConfig {
  api_key?: string;
  auto_index_interval_hours?: number;
  checksum_limit_mb?: number;
  root_dirs?: string[];
}

function getRootDirs(): string[] {
  const saved = readConfigFile().root_dirs;
  if (saved && saved.length > 0) return saved;
  return [ROOT_DIR];
}

function rootDirsLabel(dirs: string[]): string {
  return dirs.length === 1 ? dirs[0] : "Multiple drives";
}

function readConfigFile(): AppConfig {
  try {
    if (fs.existsSync(CONFIG_FILE)) return JSON.parse(fs.readFileSync(CONFIG_FILE, "utf8"));
  } catch { }
  return {};
}

function writeConfigFile(data: AppConfig): void {
  try {
    fs.mkdirSync(CONFIG_DIR, { recursive: true });
    fs.writeFileSync(CONFIG_FILE, JSON.stringify(data, null, 2), { mode: 0o600 });
  } catch { }
}

let runtimeApiKey: string | null = readConfigFile().api_key ?? null;
let isIndexingRunning = false;

function getActiveApiKey(): string | null {
  return runtimeApiKey || process.env.ANTHROPIC_API_KEY || null;
}

function getAnthropicClient() {
  const key = getActiveApiKey();
  if (!key) return null;
  return new Anthropic({ apiKey: key });
}

function resolveModel(requested?: string | null): string {
  if (requested && VALID_MODELS.includes(requested)) return requested;
  return DEFAULT_MODEL;
}

function formatFileResult(f: FileRow) {
  return {
    id: f.id,
    path: f.path,
    name: f.name,
    extension: f.extension,
    size_bytes: f.size_bytes,
    modified_at: f.modified_at ?? "",
    created_at: f.created_at ?? "",
    folder: f.folder,
    ai_summary: f.ai_summary ?? null,
    checksum: f.checksum ?? null,
  };
}

async function extractTextContent(filePath: string, ext: string): Promise<string> {
  try {
    const sizeBytes = fs.statSync(filePath).size;
    if (sizeBytes > 50 * 1024 * 1024) return "";

    if (ext === ".pdf") {
      const pdfParse = (await import("pdf-parse")).default;
      const buffer = fs.readFileSync(filePath);
      const result = await pdfParse(buffer);
      return result.text.slice(0, 10000);
    }

    if (ext === ".docx") {
      const mammoth = (await import("mammoth")).default;
      const result = await mammoth.extractRawText({ path: filePath });
      return result.value.slice(0, 10000);
    }

    const mimeType = mime.lookup(ext) || "";
    if (mimeType.startsWith("text/") || [".md", ".txt", ".csv", ".json", ".xml", ".html", ".js", ".ts", ".py", ".sh"].includes(ext)) {
      return fs.readFileSync(filePath, "utf8").slice(0, 10000);
    }

    return "";
  } catch {
    return "";
  }
}

async function computeChecksum(filePath: string, timeoutMs = 30_000): Promise<string> {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash("sha256");
    const stream = fs.createReadStream(filePath);

    const timer = setTimeout(() => {
      stream.destroy();
      reject(new Error(`Checksum timeout after ${timeoutMs}ms: ${filePath}`));
    }, timeoutMs);

    stream.on("data", (d) => hash.update(d));
    stream.on("end", () => { clearTimeout(timer); resolve(hash.digest("hex")); });
    stream.on("error", (err) => { clearTimeout(timer); reject(err); });
  });
}

async function runIndexing(rootDirs: string[], jobId: number) {
  isIndexingRunning = true;
  try {
    db.prepare("UPDATE indexing_jobs SET status = 'running', started_at = ? WHERE id = ?").run(new Date().toISOString(), jobId);

    const upsert = db.prepare(`
      INSERT INTO file_index (path, name, extension, size_bytes, modified_at, created_at, folder, content_text, ai_summary, checksum, inode, indexed_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, datetime('now'))
      ON CONFLICT(path) DO UPDATE SET
        name = excluded.name,
        extension = excluded.extension,
        size_bytes = excluded.size_bytes,
        modified_at = excluded.modified_at,
        created_at = excluded.created_at,
        folder = excluded.folder,
        content_text = excluded.content_text,
        checksum = excluded.checksum,
        inode = excluded.inode,
        indexed_at = datetime('now')
    `);

    const checksumLimitMb = readConfigFile().checksum_limit_mb ?? 100;
    let processed = 0;
    let progressCounter = 0;

    const streamOpts = {
      absolute: true,
      onlyFiles: true,
      followSymbolicLinks: false,
      ignore: ["**/node_modules/**", "**/.git/**", "**/.DS_Store", "**/.TemporaryItems/**", "**/.Spotlight-V100/**", "**/.fseventsd/**", "**/.Trashes/**"],
      dot: false,
      suppressErrors: true,
    };

    // Stream each root drive one at a time
    for (const rootDir of rootDirs) {
      const fileStream = fg.stream(["**/*"], { ...streamOpts, cwd: rootDir });

      for await (const entry of fileStream) {
        const filePath = entry as string;
        try {
          const stat = fs.statSync(filePath);
          if (!stat.isFile()) continue;

          const ext = path.extname(filePath).toLowerCase();
          const name = path.basename(filePath);
          const folder = path.dirname(filePath);

          let checksum: string | null = null;
          try {
            if (stat.size <= checksumLimitMb * 1024 * 1024) {
              checksum = await computeChecksum(filePath);
            }
          } catch { }

          let content: string | null = null;
          try { content = await extractTextContent(filePath, ext); } catch { }

          const inode = stat.ino ?? null;

          db.exec("BEGIN");
          try {
            upsert.run(filePath, name, ext || "(no extension)", stat.size, stat.mtime.toISOString(), (stat.birthtime || stat.mtime).toISOString(), folder, content, checksum, inode);
            db.exec("COMMIT");
          } catch { db.exec("ROLLBACK"); }
        } catch { }

        processed++;
        progressCounter++;
        if (progressCounter >= 100) {
          db.prepare("UPDATE indexing_jobs SET processed_files = ? WHERE id = ?").run(processed, jobId);
          progressCounter = 0;
        }
      }
    }

    db.prepare("UPDATE indexing_jobs SET status = 'completed', completed_at = ?, processed_files = ?, total_files = ? WHERE id = ?").run(new Date().toISOString(), processed, processed, jobId);
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : String(err);
    db.prepare("UPDATE indexing_jobs SET status = 'failed', error_message = ?, completed_at = ? WHERE id = ?").run(msg, new Date().toISOString(), jobId);
  } finally {
    isIndexingRunning = false;
  }
}

async function runIncrementalIndexing(rootDirs: string[], jobId: number) {
  isIndexingRunning = true;
  try {
    db.prepare("UPDATE indexing_jobs SET status = 'running', started_at = ? WHERE id = ?").run(new Date().toISOString(), jobId);

    // Find the last successful completion time
    const lastJob = db.prepare(
      "SELECT completed_at FROM indexing_jobs WHERE status = 'completed' AND id != ? ORDER BY completed_at DESC LIMIT 1"
    ).get(jobId) as { completed_at: string } | undefined;
    const lastCompletedMs = lastJob ? new Date(lastJob.completed_at).getTime() : 0;

    // Null-inode folders across all drives (pre-inode-tracking records)
    const nullInodeFolders = new Set<string>(
      (db.prepare("SELECT DISTINCT folder FROM file_index WHERE inode IS NULL").all() as { folder: string }[])
        .map(r => r.folder)
    );

    // Phase 1: collect changed dirs across all roots
    const changedDirSet = new Set<string>();
    for (const rootDir of rootDirs) {
      const allDirs = await fg(["**/"], {
        cwd: rootDir,
        absolute: true,
        onlyDirectories: true,
        followSymbolicLinks: false,
        ignore: ["**/node_modules/**", "**/.git/**", "**/.DS_Store", "**/.TemporaryItems/**", "**/.Spotlight-V100/**", "**/.fseventsd/**", "**/.Trashes/**"],
        dot: false,
        suppressErrors: true,
      });
      allDirs.push(rootDir);

      for (const dir of allDirs) {
        try {
          const s = fs.statSync(dir);
          if (s.mtime.getTime() >= lastCompletedMs || nullInodeFolders.has(dir)) {
            changedDirSet.add(dir);
          }
        } catch { }
      }
    }
    // Also include any null-inode folders that fast-glob might have missed
    for (const folder of nullInodeFolders) changedDirSet.add(folder);

    const changedDirs = [...changedDirSet];

    if (changedDirs.length === 0) {
      db.prepare("UPDATE indexing_jobs SET status = 'completed', completed_at = ?, processed_files = 0 WHERE id = ?")
        .run(new Date().toISOString(), jobId);
      return;
    }

    const upsert = db.prepare(`
      INSERT INTO file_index (path, name, extension, size_bytes, modified_at, created_at, folder, content_text, ai_summary, checksum, inode, indexed_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, datetime('now'))
      ON CONFLICT(path) DO UPDATE SET
        name = excluded.name, extension = excluded.extension, size_bytes = excluded.size_bytes,
        modified_at = excluded.modified_at, created_at = excluded.created_at, folder = excluded.folder,
        content_text = excluded.content_text, checksum = excluded.checksum, inode = excluded.inode,
        indexed_at = datetime('now')
    `);

    // Fast count-only pre-pass: stat comparisons only, no file reading — memory stays flat
    let total = 0;
    for (const dir of changedDirs) {
      const dbRows = db.prepare("SELECT path, modified_at, inode FROM file_index WHERE folder = ?").all(dir) as { path: string; modified_at: string | null; inode: number | null }[];
      const dbFiles = new Map(dbRows.map(r => [r.path, r]));
      let entries: fs.Dirent[] = [];
      try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { continue; }
      for (const entry of entries) {
        if (!entry.isFile()) continue;
        const filePath = path.join(dir, entry.name);
        try {
          const stat = fs.statSync(filePath);
          const existing = dbFiles.get(filePath);
          if (!existing || stat.mtime.toISOString() !== existing.modified_at || stat.ino !== existing.inode) total++;
        } catch { }
      }
    }
    db.prepare("UPDATE indexing_jobs SET total_files = ? WHERE id = ?").run(total, jobId);

    const checksumLimitMb = readConfigFile().checksum_limit_mb ?? 100;
    let processed = 0;
    let progressCounter = 0;

    // Process each directory immediately — never accumulate all files into memory at once
    for (const dir of changedDirs) {
      const dbFiles = new Map<string, { modified_at: string | null; inode: number | null }>();
      const dbRows = db.prepare("SELECT path, modified_at, inode FROM file_index WHERE folder = ?").all(dir) as { path: string; modified_at: string | null; inode: number | null }[];
      for (const r of dbRows) dbFiles.set(r.path, { modified_at: r.modified_at, inode: r.inode });

      let diskEntries: fs.Dirent[] = [];
      try { diskEntries = fs.readdirSync(dir, { withFileTypes: true }); } catch { continue; }

      const diskPaths = new Set<string>();
      for (const entry of diskEntries) {
        if (!entry.isFile()) continue;
        const filePath = path.join(dir, entry.name);
        diskPaths.add(filePath);

        try {
          const stat = fs.statSync(filePath);
          const existing = dbFiles.get(filePath);
          const needsProcess = !existing || stat.mtime.toISOString() !== existing.modified_at || stat.ino !== existing.inode;
          if (!needsProcess) continue;

          const ext = path.extname(filePath).toLowerCase();
          const name = path.basename(filePath);
          const folder = path.dirname(filePath);

          let checksum: string | null = null;
          try { if (stat.size <= checksumLimitMb * 1024 * 1024) checksum = await computeChecksum(filePath); } catch { }

          let content: string | null = null;
          try { content = await extractTextContent(filePath, ext); } catch { }

          db.exec("BEGIN");
          try {
            upsert.run(filePath, name, ext || "(no extension)", stat.size, stat.mtime.toISOString(), (stat.birthtime || stat.mtime).toISOString(), folder, content, checksum, stat.ino ?? null);
            db.exec("COMMIT");
          } catch { db.exec("ROLLBACK"); }

          processed++;
          progressCounter++;
          if (progressCounter >= 50) {
            db.prepare("UPDATE indexing_jobs SET processed_files = ? WHERE id = ?").run(processed, jobId);
            progressCounter = 0;
          }
        } catch { }
      }

      // Remove stale DB entries for this directory immediately
      for (const [dbPath] of dbFiles) {
        if (!diskPaths.has(dbPath)) {
          db.prepare("DELETE FROM file_index WHERE path = ?").run(dbPath);
        }
      }
    }

    db.prepare("UPDATE indexing_jobs SET status = 'completed', completed_at = ?, processed_files = ?, total_files = ? WHERE id = ?")
      .run(new Date().toISOString(), processed, processed, jobId);
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : String(err);
    db.prepare("UPDATE indexing_jobs SET status = 'failed', error_message = ?, completed_at = ? WHERE id = ?")
      .run(msg, new Date().toISOString(), jobId);
  } finally {
    isIndexingRunning = false;
  }
}

router.get("/file-finder/ai-config", (_req: Request, res: Response) => {
  const envKeySet = !!process.env.ANTHROPIC_API_KEY;
  const runtimeKeySet = !!runtimeApiKey;
  res.json({ configured: envKeySet || runtimeKeySet, source: runtimeKeySet ? "runtime" : (envKeySet ? "env" : "none") });
});

router.post("/file-finder/ai-config", (req: Request, res: Response) => {
  const { api_key } = req.body as { api_key?: string };
  if (api_key && api_key.trim()) {
    runtimeApiKey = api_key.trim();
    writeConfigFile({ api_key: runtimeApiKey });
    res.json({ ok: true, message: "API key saved." });
  } else {
    runtimeApiKey = null;
    writeConfigFile({});
    res.json({ ok: true, message: "API key cleared." });
  }
});

router.get("/file-finder/search", async (req: Request, res: Response) => {
  try {
    const {
      query = "",
      types = "",
      date_from,
      date_to,
      size_min,
      size_max,
      folder_scope,
      sort_by = "date",
      sort_order = "desc",
      page = "1",
      limit = "50",
    } = req.query as Record<string, string>;

    const pageNum = Math.max(1, parseInt(page));
    const limitNum = Math.min(200, Math.max(1, parseInt(limit)));
    const offset = (pageNum - 1) * limitNum;

    const conditions: string[] = [];
    const params: (string | number)[] = [];

    if (types) {
      const extList = types.split(",").map((t) => t.trim().toLowerCase()).filter(Boolean).map((t) => (t.startsWith(".") ? t : "." + t));
      if (extList.length === 1) { conditions.push("extension = ?"); params.push(extList[0]); }
      else if (extList.length > 1) { conditions.push(`extension IN (${extList.map(() => "?").join(",")})`); params.push(...extList); }
    }
    if (date_from) { conditions.push("modified_at >= ?"); params.push(new Date(date_from).toISOString()); }
    if (date_to) { conditions.push("modified_at <= ?"); params.push(new Date(date_to).toISOString()); }
    if (size_min) { conditions.push("size_bytes >= ?"); params.push(parseInt(size_min)); }
    if (size_max) { conditions.push("size_bytes <= ?"); params.push(parseInt(size_max)); }
    if (folder_scope) { conditions.push("folder LIKE ?"); params.push(`${folder_scope}%`); }

    const where = conditions.length > 0 ? `WHERE ${conditions.join(" AND ")}` : "";
    const sortCol = sort_by === "name" ? "name" : sort_by === "size" ? "size_bytes" : sort_by === "type" ? "extension" : "modified_at";
    const order = sort_order === "asc" ? "ASC" : "DESC";

    let allFiles: FileRow[];

    if (query.trim()) {
      const q = query.trim();

      // Split into words — each word must appear in the filename.
      // Searching name only (not path) keeps results predictable.
      // Word-level matching handles separators: "76-trombones" matches "76 trombones"
      // because both "76" and "trombones" are substrings of "76-trombones".
      const words = q.split(/\s+/).filter(Boolean);

      // Stage 1: all words must appear in the filename
      const wordClauses = words.map(() => "LOWER(name) LIKE LOWER(?)");
      const wordParams: (string | number)[] = words.map((w) => `%${w}%`);

      const nameConditions = [...conditions, ...wordClauses];
      const nameParams: (string | number)[] = [...params, ...wordParams];
      const nameWhere = `WHERE ${nameConditions.join(" AND ")}`;
      const exact = db.prepare(`SELECT * FROM file_index ${nameWhere} ORDER BY ${sortCol} ${order}`).all(...nameParams) as FileRow[];

      if (exact.length > 0) {
        // Sort so files whose name contains the full query phrase rank first
        allFiles = exact.sort((a, b) => {
          const aFull = a.name.toLowerCase().includes(q.toLowerCase()) ? 0 : 1;
          const bFull = b.name.toLowerCase().includes(q.toLowerCase()) ? 0 : 1;
          return aFull - bFull;
        });
      } else if (q.length >= 5) {
        // Stage 2: tight fuzzy fallback — only fires when zero exact matches
        // AND query is long enough that near-misses are meaningful.
        // Threshold 0.2 catches 1-character typos in long words ("trambones" →
        // "trombones") but rejects loose matches on short words ("hose" → "home").
        const base = db.prepare(`SELECT * FROM file_index ${where} ORDER BY ${sortCol} ${order}`).all(...params) as FileRow[];
        const fuse = new Fuse(base, { keys: ["name"], threshold: 0.2, includeScore: true });
        allFiles = fuse.search(q).map((r) => r.item);
      }
    } else {
      allFiles = db.prepare(`SELECT * FROM file_index ${where} ORDER BY ${sortCol} ${order}`).all(...params) as FileRow[];
    }

    const total = allFiles.length;
    const paged = allFiles.slice(offset, offset + limitNum);

    res.json({ files: paged.map(formatFileResult), total, page: pageNum, limit: limitNum });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/ai-search", async (req: Request, res: Response) => {
  try {
    const { description, folder_scope, model } = req.body;
    const client = getAnthropicClient();

    const where = folder_scope ? "WHERE folder LIKE ?" : "";
    const params = folder_scope ? [`${folder_scope}%`] : [];
    const allFiles = db.prepare(`SELECT id, path, name, extension, folder, size_bytes, modified_at, ai_summary FROM file_index ${where} LIMIT 2000`).all(...params) as FileRow[];

    if (!client) {
      const fuse = new Fuse(allFiles, { keys: ["name", "folder", "ai_summary"], threshold: 0.4 });
      const results = fuse.search(description).slice(0, 20).map((r) => r.item);
      const ids = results.map((r) => r.id);
      const full = ids.length > 0 ? (db.prepare(`SELECT * FROM file_index WHERE id IN (${ids.map(() => "?").join(",")})`).all(...ids) as FileRow[]) : [];
      return res.json({ files: full.map(formatFileResult), explanation: "Fuzzy search results (Claude API key not configured).", total: full.length });
    }

    const fileList = allFiles.slice(0, 1000).map((f) => `ID:${f.id} | ${f.name} | ${f.extension} | ${f.folder} | ${f.ai_summary ?? ""}`).join("\n");

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 1024,
      messages: [{ role: "user", content: `You are a file search assistant. The user is looking for: "${description}"\n\nFile list (ID | name | extension | folder | summary):\n${fileList}\n\nReturn JSON with:\n- "ids": array of up to 20 matching file IDs, ordered by relevance\n- "explanation": one-sentence explanation\n\nReturn ONLY valid JSON.` }],
    });

    const text = response.content[0].type === "text" ? response.content[0].text : "{}";
    let parsed: { ids?: number[]; explanation?: string } = {};
    try { parsed = JSON.parse(text); } catch { parsed = { ids: [], explanation: "Could not parse AI response." }; }

    const matchedIds = parsed.ids ?? [];
    const matchedFiles = matchedIds.length > 0 ? (db.prepare(`SELECT * FROM file_index WHERE id IN (${matchedIds.map(() => "?").join(",")})`).all(...matchedIds) as FileRow[]) : [];
    const sortedFiles = matchedIds.map((id) => matchedFiles.find((f) => f.id === id)).filter(Boolean) as FileRow[];

    res.json({ files: sortedFiles.map(formatFileResult), explanation: parsed.explanation ?? "", total: sortedFiles.length });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/ai-summarize", async (req: Request, res: Response) => {
  try {
    const { file_id, model } = req.body;
    const client = getAnthropicClient();

    const file = db.prepare("SELECT * FROM file_index WHERE id = ?").get(file_id) as FileRow | undefined;
    if (!file) return res.status(404).json({ error: "File not found" });

    if (!client) {
      const fallback = `${file.name} — ${file.extension} file, ${((file.size_bytes ?? 0) / 1024).toFixed(0)} KB, last modified ${file.modified_at ? new Date(file.modified_at).toLocaleDateString() : "unknown"}`;
      db.prepare("UPDATE file_index SET ai_summary = ? WHERE id = ?").run(fallback, file_id);
      return res.json({ summary: fallback, file_id });
    }

    let content = file.content_text ?? "";
    if (!content) content = await extractTextContent(file.path, file.extension);

    const prompt = content
      ? `Summarize this file in 1-2 sentences. File: "${file.name}" (${file.extension})\n\nContent:\n${content.slice(0, 3000)}`
      : `Describe what this file likely contains based on its name and type. File: "${file.name}" (${file.extension}), size: ${((file.size_bytes ?? 0) / 1024).toFixed(0)} KB, folder: "${file.folder}"`;

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 200,
      messages: [{ role: "user", content: prompt }],
    });

    const summary = response.content[0].type === "text" ? response.content[0].text : "";
    db.prepare("UPDATE file_index SET ai_summary = ? WHERE id = ?").run(summary, file_id);

    res.json({ summary, file_id });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/more-like-this", async (req: Request, res: Response) => {
  try {
    const { file_id, limit: limitRaw = 20, model } = req.body;
    const limitNum = Math.min(50, Math.max(1, parseInt(String(limitRaw))));

    const file = db.prepare("SELECT * FROM file_index WHERE id = ?").get(file_id) as FileRow | undefined;
    if (!file) return res.status(404).json({ files: [], total: 0, page: 1, limit: limitNum });

    const client = getAnthropicClient();
    const allFiles = db.prepare("SELECT id, path, name, extension, folder, size_bytes, modified_at, ai_summary FROM file_index WHERE id != ? LIMIT 2000").all(file_id) as FileRow[];

    if (!client) {
      const fuse = new Fuse(allFiles, { keys: ["name", "folder", "extension", "ai_summary"], threshold: 0.5 });
      const results = fuse.search(file.name).slice(0, limitNum).map((r) => r.item);
      const ids = results.map((r) => r.id);
      const full = ids.length > 0 ? (db.prepare(`SELECT * FROM file_index WHERE id IN (${ids.map(() => "?").join(",")})`).all(...ids) as FileRow[]) : [];
      return res.json({ files: full.map(formatFileResult), total: full.length, page: 1, limit: limitNum });
    }

    const fileList = allFiles.slice(0, 800).map((f) => `ID:${f.id} | ${f.name} | ${f.extension} | ${f.folder} | ${f.ai_summary ?? ""}`).join("\n");

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 512,
      messages: [{ role: "user", content: `Find files similar to: "${file.name}" (${file.extension}, folder: ${file.folder}, summary: "${file.ai_summary ?? "none"}")\n\nFile list:\n${fileList}\n\nReturn JSON: {"ids": [array of up to ${limitNum} most similar file IDs]}. Return ONLY valid JSON.` }],
    });

    const text = response.content[0].type === "text" ? response.content[0].text : "{}";
    let parsed: { ids?: number[] } = {};
    try { parsed = JSON.parse(text); } catch { parsed = { ids: [] }; }

    const matchedIds = parsed.ids ?? [];
    const matchedFiles = matchedIds.length > 0 ? (db.prepare(`SELECT * FROM file_index WHERE id IN (${matchedIds.map(() => "?").join(",")})`).all(...matchedIds) as FileRow[]) : [];

    res.json({ files: matchedFiles.map(formatFileResult), total: matchedFiles.length, page: 1, limit: limitNum });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.delete("/file-finder/files", async (req: Request, res: Response) => {
  try {
    const { paths } = req.body as { paths: string[] };
    if (!Array.isArray(paths) || paths.length === 0) return res.status(400).json({ error: "paths array required" });
    const results: { path: string; success: boolean; error?: string }[] = [];
    for (const filePath of paths) {
      try {
        fs.unlinkSync(filePath);
        db.prepare("DELETE FROM file_index WHERE path = ?").run(filePath);
        results.push({ path: filePath, success: true });
      } catch (err) {
        results.push({ path: filePath, success: false, error: err instanceof Error ? err.message : String(err) });
      }
    }
    res.json({ results });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/open", async (req: Request, res: Response) => {
  try {
    const { path: filePath } = req.body;
    if (!filePath) return res.status(400).json({ success: false, message: "Path required" });
    if (process.platform === "darwin") {
      await execAsync(`open -R "${filePath.replace(/"/g, '\\"')}"`);
      res.json({ success: true, message: "Opened in Finder" });
    } else {
      res.json({ success: false, message: "Open in Finder is only available on macOS" });
    }
  } catch (err: unknown) {
    res.status(500).json({ success: false, message: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/duplicates", async (req: Request, res: Response) => {
  try {
    const { folder_scope } = req.query as Record<string, string>;
    const folderFilter = folder_scope ? "AND fi.folder LIKE ?" : "";
    const params: unknown[] = folder_scope ? [`${folder_scope}%`] : [];

    // Inode-aware duplicate query:
    // 1. Pick one representative path per unique physical file (checksum + inode)
    // 2. Only include checksum groups where multiple distinct inodes exist
    //    (= true separate copies, not hard links to the same physical file)
    // Falls back to inode-unaware behaviour for files indexed before inode tracking.
    const rows = db.prepare(`
      SELECT fi.*
      FROM file_index fi
      WHERE fi.checksum IS NOT NULL
        AND (
          -- Inode-aware: one representative per (checksum, inode), only where >1 distinct inode exists
          (fi.inode IS NOT NULL
            AND fi.id = (
              SELECT MIN(id) FROM file_index
              WHERE checksum = fi.checksum AND inode = fi.inode
            )
            AND fi.checksum IN (
              SELECT checksum FROM file_index
              WHERE checksum IS NOT NULL AND inode IS NOT NULL
              GROUP BY checksum HAVING COUNT(DISTINCT inode) > 1
            )
          )
          OR
          -- Fallback for legacy rows without inode data
          (fi.inode IS NULL
            AND fi.checksum IN (
              SELECT checksum FROM file_index
              WHERE checksum IS NOT NULL AND inode IS NULL
              GROUP BY checksum HAVING COUNT(*) > 1
            )
          )
        )
        ${folderFilter}
      ORDER BY fi.checksum, fi.size_bytes DESC
      LIMIT 5000
    `).all(...params) as FileRow[];

    // Group rows by checksum in JS
    const groupMap = new Map<string, FileRow[]>();
    for (const row of rows) {
      const key = row.checksum!;
      if (!groupMap.has(key)) groupMap.set(key, []);
      groupMap.get(key)!.push(row);
    }

    let totalWastedBytes = 0;
    const result = [];
    for (const [checksum, files] of groupMap) {
      const wastedBytes = (files[0]?.size_bytes ?? 0) * (files.length - 1);
      totalWastedBytes += wastedBytes;
      result.push({ checksum, files: files.map(formatFileResult), wasted_bytes: wastedBytes });
    }

    result.sort((a, b) => b.wasted_bytes - a.wasted_bytes);
    res.json({ groups: result, total_wasted_bytes: totalWastedBytes });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/index/start", async (req: Request, res: Response) => {
  try {
    const rootDirs = getRootDirs();
    const label = rootDirsLabel(rootDirs);

    if (isIndexingRunning) {
      const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
      return res.json({ status: "running", root_dir: job?.root_dir ?? label, total_files: job?.total_files ?? 0, processed_files: job?.processed_files ?? 0, started_at: job?.started_at ?? null, completed_at: null, error_message: null });
    }

    const now = new Date().toISOString();
    const result = db.prepare("INSERT INTO indexing_jobs (status, type, root_dir, total_files, processed_files, started_at, created_at) VALUES ('running', 'full', ?, 0, 0, ?, ?)").run(label, now, now);
    const jobId = Number(result.lastInsertRowid);

    runIndexing(rootDirs, jobId).catch(() => { });

    res.json({ status: "running", type: "full", root_dir: label, total_files: 0, processed_files: 0, started_at: now, completed_at: null, error_message: null });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/index/status", async (req: Request, res: Response) => {
  try {
    const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
    if (!job) return res.json({ status: "idle", type: null, root_dir: null, total_files: 0, processed_files: 0, started_at: null, completed_at: null, error_message: null });
    res.json({ status: job.status, type: job.type ?? "full", root_dir: job.root_dir, total_files: job.total_files, processed_files: job.processed_files, started_at: job.started_at, completed_at: job.completed_at, error_message: job.error_message });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/index/incremental", async (req: Request, res: Response) => {
  try {
    const rootDirs = getRootDirs();
    const label = rootDirsLabel(rootDirs);

    if (isIndexingRunning) {
      const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
      return res.json({ status: "running", type: job?.type ?? "incremental", root_dir: job?.root_dir ?? label, total_files: job?.total_files ?? 0, processed_files: job?.processed_files ?? 0, started_at: job?.started_at ?? null, completed_at: null, error_message: null });
    }

    const now = new Date().toISOString();
    const result = db.prepare("INSERT INTO indexing_jobs (status, type, root_dir, total_files, processed_files, started_at, created_at) VALUES ('running', 'incremental', ?, 0, 0, ?, ?)").run(label, now, now);
    const jobId = Number(result.lastInsertRowid);

    runIncrementalIndexing(rootDirs, jobId).catch(() => { });

    res.json({ status: "running", type: "incremental", root_dir: label, total_files: 0, processed_files: 0, started_at: now, completed_at: null, error_message: null });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/folder-search", async (req: Request, res: Response) => {
  try {
    const { q = "" } = req.query as Record<string, string>;
    if (!q.trim()) return res.json({ folders: [] });

    const words = q.trim().split(/\s+/).filter(Boolean);

    // Extract the folder NAME (last path segment) using SQLite string functions.
    // Formula: SUBSTR(folder, LENGTH(folder) - INSTR('X' || REVERSE(folder), '/') + 3)
    //   - REVERSE(folder) puts the last '/' near the front
    //   - Prepending 'X' ensures INSTR always finds a '/' (handles no-slash edge case)
    //   - Result: only the last segment, e.g. "76 Trombones" not the full path
    // This prevents false matches from numeric folders like "114767" containing "76"
    // as a substring, or from ancestor path segments.
    const folderNameExpr = `SUBSTR(folder, LENGTH(folder) - INSTR('X' || REVERSE(folder), '/') + 3)`;
    const nameClauses = words.map(() => `LOWER(${folderNameExpr}) LIKE LOWER(?)`);
    const nameParams = words.map((w) => `%${w}%`);

    // ORDER BY LENGTH(folder) returns shallower (canonical) folders first.
    const rows = db.prepare(
      `SELECT DISTINCT folder FROM file_index WHERE ${nameClauses.join(" AND ")} ORDER BY LENGTH(folder), folder LIMIT 50`
    ).all(...nameParams) as { folder: string }[];

    res.json({ folders: rows.map((r) => r.folder) });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/folders", async (req: Request, res: Response) => {
  try {
    const rows = db.prepare("SELECT DISTINCT folder FROM file_index LIMIT 200").all() as { folder: string }[];
    const folders = rows.map((r) => r.folder).sort();

    const topLevel = new Set<string>();
    for (const f of folders) {
      const rel = f.replace(ROOT_DIR, "").replace(/^\//, "");
      const parts = rel.split("/");
      if (parts[0]) topLevel.add(path.join(ROOT_DIR, parts[0]));
    }

    res.json({ folders: [...topLevel].sort(), root_dir: ROOT_DIR });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/stats", async (req: Request, res: Response) => {
  try {
    const totals = db.prepare("SELECT COUNT(*) as count, SUM(size_bytes) as total_size FROM file_index").get() as { count: number; total_size: number };
    const typeRows = db.prepare("SELECT extension, COUNT(*) as count, SUM(size_bytes) as total_size FROM file_index GROUP BY extension ORDER BY COUNT(*) DESC LIMIT 50").all() as { extension: string; count: number; total_size: number }[];
    const lastJob = db.prepare("SELECT * FROM indexing_jobs WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1").get() as JobRow | undefined;

    res.json({
      total_files: Number(totals?.count ?? 0),
      total_size_bytes: Number(totals?.total_size ?? 0),
      file_types: typeRows.map((r) => ({ extension: r.extension, count: Number(r.count), total_size_bytes: Number(r.total_size ?? 0) })),
      last_indexed: lastJob?.completed_at ?? null,
      root_dir: ROOT_DIR,
    });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

// ---------------------------------------------------------------
// App config GET/POST
// ---------------------------------------------------------------
router.get("/file-finder/app-config", (_req: Request, res: Response) => {
  const config = readConfigFile();
  res.json({
    auto_index_interval_hours: config.auto_index_interval_hours ?? 12,
    checksum_limit_mb: config.checksum_limit_mb ?? 100,
    root_dirs: getRootDirs(),
  });
});

router.post("/file-finder/app-config", (req: Request, res: Response) => {
  try {
    const config = readConfigFile();
    const { auto_index_interval_hours, checksum_limit_mb, root_dirs } = req.body as Partial<AppConfig>;
    if (auto_index_interval_hours !== undefined) config.auto_index_interval_hours = Number(auto_index_interval_hours);
    if (checksum_limit_mb !== undefined) config.checksum_limit_mb = Number(checksum_limit_mb);
    if (root_dirs !== undefined && Array.isArray(root_dirs)) config.root_dirs = root_dirs.filter(Boolean);
    writeConfigFile(config);
    res.json({ ok: true });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

// ---------------------------------------------------------------
// Scheduled auto-index — dynamic, reads config each hour
// ---------------------------------------------------------------
async function runScheduledIncremental() {
  if (isIndexingRunning) return;
  const rootDirs = getRootDirs();
  const label = rootDirsLabel(rootDirs);
  const now = new Date().toISOString();
  try {
    const result = db.prepare(
      "INSERT INTO indexing_jobs (status, type, root_dir, total_files, processed_files, started_at, created_at) VALUES ('running', 'incremental', ?, 0, 0, ?, ?)"
    ).run(label, now, now);
    runIncrementalIndexing(rootDirs, Number(result.lastInsertRowid)).catch(() => {});
  } catch { }
}

function scheduleAutoIndex() {
  // Check every hour — runs if enough time has passed since last completed job
  setInterval(() => {
    const config = readConfigFile();
    const intervalHours = config.auto_index_interval_hours ?? 12;
    if (intervalHours === 0 || isIndexingRunning) return;

    const lastJob = db.prepare(
      "SELECT completed_at FROM indexing_jobs WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1"
    ).get() as { completed_at: string } | undefined;

    if (!lastJob) return; // never fully indexed — don't auto-run

    const elapsedHours = (Date.now() - new Date(lastJob.completed_at).getTime()) / (1000 * 60 * 60);
    if (elapsedHours >= intervalHours) runScheduledIncremental();
  }, 60 * 60 * 1000);
}

scheduleAutoIndex();

export default router;
