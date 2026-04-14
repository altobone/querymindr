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

function readConfigFile(): { api_key?: string } {
  try {
    if (fs.existsSync(CONFIG_FILE)) return JSON.parse(fs.readFileSync(CONFIG_FILE, "utf8"));
  } catch { }
  return {};
}

function writeConfigFile(data: { api_key?: string }): void {
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

async function computeChecksum(filePath: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash("sha256");
    const stream = fs.createReadStream(filePath);
    stream.on("data", (d) => hash.update(d));
    stream.on("end", () => resolve(hash.digest("hex")));
    stream.on("error", reject);
  });
}

async function runIndexing(rootDir: string, jobId: number) {
  isIndexingRunning = true;
  try {
    db.prepare("UPDATE indexing_jobs SET status = 'running', started_at = ? WHERE id = ?").run(new Date().toISOString(), jobId);

    const entries = await fg(["**/*"], {
      cwd: rootDir,
      absolute: true,
      onlyFiles: true,
      followSymbolicLinks: false,
      ignore: ["**/node_modules/**", "**/.git/**", "**/.DS_Store", "**/.TemporaryItems/**", "**/.Spotlight-V100/**", "**/.fseventsd/**", "**/.Trashes/**"],
      dot: false,
      suppressErrors: true,
    });

    const totalFiles = entries.length;
    db.prepare("UPDATE indexing_jobs SET total_files = ? WHERE id = ?").run(totalFiles, jobId);

    const upsert = db.prepare(`
      INSERT INTO file_index (path, name, extension, size_bytes, modified_at, created_at, folder, content_text, ai_summary, checksum, indexed_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, datetime('now'))
      ON CONFLICT(path) DO UPDATE SET
        name = excluded.name,
        extension = excluded.extension,
        size_bytes = excluded.size_bytes,
        modified_at = excluded.modified_at,
        created_at = excluded.created_at,
        folder = excluded.folder,
        content_text = excluded.content_text,
        checksum = excluded.checksum,
        indexed_at = datetime('now')
    `);

    let processed = 0;
    const batchSize = 50;

    for (let i = 0; i < entries.length; i += batchSize) {
      const batch = entries.slice(i, i + batchSize);

      db.exec("BEGIN");
      try {
        for (const filePath of batch) {
          try {
            const stat = fs.statSync(filePath);
            if (!stat.isFile()) { processed++; continue; }

            const ext = path.extname(filePath).toLowerCase();
            const name = path.basename(filePath);
            const folder = path.dirname(filePath);

            let checksum: string | null = null;
            try {
              if (stat.size < 100 * 1024 * 1024) checksum = crypto.createHash("sha256").update(fs.readFileSync(filePath)).digest("hex");
            } catch { }

            upsert.run(filePath, name, ext || "(no extension)", stat.size, stat.mtime.toISOString(), (stat.birthtime || stat.mtime).toISOString(), folder, null, checksum);
          } catch { }
          processed++;
        }
        db.exec("COMMIT");
      } catch (txErr) {
        db.exec("ROLLBACK");
        throw txErr;
      }

      // Content extraction done separately (async) — update after batch
      for (const filePath of batch) {
        try {
          const ext = path.extname(filePath).toLowerCase();
          const content = await extractTextContent(filePath, ext);
          if (content) {
            db.prepare("UPDATE file_index SET content_text = ? WHERE path = ?").run(content, filePath);
          }
        } catch { }
      }

      db.prepare("UPDATE indexing_jobs SET processed_files = ? WHERE id = ?").run(processed, jobId);
    }

    db.prepare("UPDATE indexing_jobs SET status = 'completed', completed_at = ?, processed_files = ? WHERE id = ?").run(new Date().toISOString(), processed, jobId);
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : String(err);
    db.prepare("UPDATE indexing_jobs SET status = 'failed', error_message = ?, completed_at = ? WHERE id = ?").run(msg, new Date().toISOString(), jobId);
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

    let allFiles = db.prepare(`SELECT * FROM file_index ${where} ORDER BY ${sortCol} ${order}`).all(...params) as FileRow[];

    if (query.trim()) {
      const fuse = new Fuse(allFiles, { keys: ["name", "folder", "content_text"], threshold: 0.4, includeScore: true });
      allFiles = fuse.search(query.trim()).map((r) => r.item);
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
    const where = folder_scope ? "WHERE checksum IS NOT NULL AND folder LIKE ?" : "WHERE checksum IS NOT NULL";
    const params = folder_scope ? [`${folder_scope}%`] : [];

    const groups = db.prepare(`SELECT checksum, COUNT(*) as count, SUM(size_bytes) as total_size FROM file_index ${where} GROUP BY checksum HAVING COUNT(*) > 1`).all(...params) as { checksum: string; count: number; total_size: number }[];

    let totalWastedBytes = 0;
    const result = [];

    for (const group of groups) {
      const files = db.prepare("SELECT * FROM file_index WHERE checksum = ?").all(group.checksum) as FileRow[];
      const wastedBytes = (files[0]?.size_bytes ?? 0) * (files.length - 1);
      totalWastedBytes += wastedBytes;
      result.push({ checksum: group.checksum, files: files.map(formatFileResult), wasted_bytes: wastedBytes });
    }

    result.sort((a, b) => b.wasted_bytes - a.wasted_bytes);
    res.json({ groups: result, total_wasted_bytes: totalWastedBytes });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/index/start", async (req: Request, res: Response) => {
  try {
    const { root_dir } = req.body;
    const rootDir = root_dir || ROOT_DIR;

    if (isIndexingRunning) {
      const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
      return res.json({ status: "running", root_dir: job?.root_dir ?? rootDir, total_files: job?.total_files ?? 0, processed_files: job?.processed_files ?? 0, started_at: job?.started_at ?? null, completed_at: null, error_message: null });
    }

    const now = new Date().toISOString();
    const result = db.prepare("INSERT INTO indexing_jobs (status, root_dir, total_files, processed_files, started_at, created_at) VALUES ('running', ?, 0, 0, ?, ?)").run(rootDir, now, now);
    const jobId = Number(result.lastInsertRowid);

    runIndexing(rootDir, jobId).catch(() => { });

    res.json({ status: "running", root_dir: rootDir, total_files: 0, processed_files: 0, started_at: now, completed_at: null, error_message: null });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/index/status", async (req: Request, res: Response) => {
  try {
    const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
    if (!job) return res.json({ status: "idle", root_dir: null, total_files: 0, processed_files: 0, started_at: null, completed_at: null, error_message: null });
    res.json({ status: job.status, root_dir: job.root_dir, total_files: job.total_files, processed_files: job.processed_files, started_at: job.started_at, completed_at: job.completed_at, error_message: job.error_message });
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

export default router;
