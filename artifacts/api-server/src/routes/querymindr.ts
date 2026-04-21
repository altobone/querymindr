import { Router, IRouter, Request, Response } from "express";
import { exec } from "child_process";
import { promisify } from "util";
import * as fs from "fs";
import * as path from "path";
import * as crypto from "crypto";
import * as os from "os";
import fg from "fast-glob";
import Fuse from "fuse.js";
import mime from "mime-types";
import { db, FileRow, JobRow, kvGet, kvSet } from "../lib/querymindr-db";
import { validateKey } from "../lib/querymindr-license";

const router: IRouter = Router();
const execAsync = promisify(exec);

const ROOT_DIR = process.env.ROOT_DIR || process.cwd();

const CONFIG_DIR = path.join(os.homedir(), ".config", "querymindr");
const CONFIG_FILE = path.join(CONFIG_DIR, "config.json");

interface AppConfig {
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

let isIndexingRunning = false;

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

router.get("/querymindr/search", async (req: Request, res: Response) => {
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

    // Type synonym map — translates natural-language words in the query into extension filters
    const TYPE_SYNONYMS: Record<string, string[]> = {
      photo: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      photos: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      picture: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      pictures: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      image: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      images: [".jpg",".jpeg",".png",".heic",".heif",".gif",".webp",".tiff",".tif",".bmp",".raw",".cr2",".nef",".arw"],
      video: [".mp4",".mov",".avi",".mkv",".m4v",".wmv",".flv",".webm",".mts",".m2ts",".mpg",".mpeg"],
      videos: [".mp4",".mov",".avi",".mkv",".m4v",".wmv",".flv",".webm",".mts",".m2ts",".mpg",".mpeg"],
      movie: [".mp4",".mov",".avi",".mkv",".m4v",".wmv",".flv",".webm",".mts",".m2ts",".mpg",".mpeg"],
      movies: [".mp4",".mov",".avi",".mkv",".m4v",".wmv",".flv",".webm",".mts",".m2ts",".mpg",".mpeg"],
      audio: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      music: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      sound: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      song: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      songs: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      track: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      tracks: [".mp3",".wav",".aif",".aiff",".flac",".m4a",".ogg",".wma",".aac",".opus"],
      stem: [".wav",".aif",".aiff",".flac"],
      stems: [".wav",".aif",".aiff",".flac"],
      doc: [".pdf",".doc",".docx",".txt",".odt",".rtf",".md",".pages"],
      docs: [".pdf",".doc",".docx",".txt",".odt",".rtf",".md",".pages"],
      document: [".pdf",".doc",".docx",".txt",".odt",".rtf",".md",".pages"],
      documents: [".pdf",".doc",".docx",".txt",".odt",".rtf",".md",".pages"],
      pdf: [".pdf"],
      spreadsheet: [".xlsx",".xls",".csv",".ods",".numbers"],
      spreadsheets: [".xlsx",".xls",".csv",".ods",".numbers"],
      project: [".als",".logicx",".ptx",".flp",".reason",".cpr",".npr",".omf"],
      projects: [".als",".logicx",".ptx",".flp",".reason",".cpr",".npr",".omf"],
      session: [".als",".logicx",".ptx",".flp",".reason",".cpr",".npr",".omf"],
      sessions: [".als",".logicx",".ptx",".flp",".reason",".cpr",".npr",".omf"],
      archive: [".zip",".rar",".tar",".gz",".7z",".bz2"],
      archives: [".zip",".rar",".tar",".gz",".7z",".bz2"],
    };

    // Detect synonym words in query and convert to extension filters
    let effectiveQuery = query;
    let effectiveTypes = types;
    if (query.trim() && !types) {
      const qWords = query.toLowerCase().split(/\s+/);
      const synonymWords = qWords.filter(w => TYPE_SYNONYMS[w]);
      if (synonymWords.length > 0) {
        const synonymExts = [...new Set(synonymWords.flatMap(w => TYPE_SYNONYMS[w]))];
        effectiveTypes = synonymExts.map(e => e.slice(1)).join(","); // strip leading dot
        effectiveQuery = qWords.filter(w => !TYPE_SYNONYMS[w]).join(" ").trim();
      }
    }

    const conditions: string[] = [];
    const params: (string | number)[] = [];

    if (effectiveTypes) {
      const extList = effectiveTypes.split(",").map((t) => t.trim().toLowerCase()).filter(Boolean).map((t) => (t.startsWith(".") ? t : "." + t));
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

    let allFiles: FileRow[] = [];
    let fuzzyUsed = false;

    if (effectiveQuery.trim()) {
      const q = effectiveQuery.trim();
      const words = q.split(/\s+/).filter(Boolean);

      // ── Stage 1: Exact match ─────────────────────────────────────────────────
      // Each word must appear in the filename OR the folder path (case-insensitive
      // substring). This lets "2023 sessions" find files inside a folder called
      // "2023 Sessions" even when the filename itself is just "track01.wav".
      //
      // Ranking within exact results (best → worst):
      //   A) full phrase appears in the filename
      //   B) all words appear in the filename
      //   C) all words appear somewhere (name or folder)
      const wordClauses = words.map(() => "(LOWER(name) LIKE LOWER(?) OR LOWER(folder) LIKE LOWER(?))");
      const wordParams: (string | number)[] = words.flatMap((w) => [`%${w}%`, `%${w}%`]);

      const exactConditions = [...conditions, ...wordClauses];
      const exactParams: (string | number)[] = [...params, ...wordParams];
      const exactWhere = `WHERE ${exactConditions.join(" AND ")}`;
      const exact = db.prepare(`SELECT * FROM file_index ${exactWhere} ORDER BY ${sortCol} ${order}`).all(...exactParams) as FileRow[];

      if (exact.length > 0) {
        allFiles = exact.sort((a, b) => {
          const aRank =
            a.name.toLowerCase().includes(q.toLowerCase()) ? 0 :     // full phrase in name
            words.every(w => a.name.toLowerCase().includes(w.toLowerCase())) ? 1 : // all words in name
            2;                                                          // words split across name+folder
          const bRank =
            b.name.toLowerCase().includes(q.toLowerCase()) ? 0 :
            words.every(w => b.name.toLowerCase().includes(w.toLowerCase())) ? 1 :
            2;
          return aRank - bRank;
        });
      } else if (q.length >= 3) {

        // ── Stage 2: Fuzzy fallback ───────────────────────────────────────────
        // Only fires when zero exact matches were found.
        //
        // Improvements:
        //   • Stem expansion: each word is expanded into morphological variants
        //     ("recordings" → recording, recorded, record) so Fuse finds roots
        //     even when the user types an inflected form.
        //   • n-gram SQL pre-filter applied to BOTH name and folder, so fuzzy
        //     can surface files whose folder name closely matches the query.
        //   • Combined-score ranking: instead of ordering by the longest word's
        //     Fuse score alone, we sum each word's best score per file and sort
        //     ascending — lower combined score = better overall match.

        fuzzyUsed = true;

        // Stem expansion: generate plausible root/variant forms of a word.
        // This is intentionally conservative — it strips common English suffixes
        // without a full morphological analyser, so false stems are minimal.
        const getStems = (w: string): string[] => {
          const v = new Set([w]);
          if (w.endsWith("ings") && w.length > 6)  { v.add(w.slice(0, -4)); v.add(w.slice(0, -4) + "e"); }
          if (w.endsWith("ing")  && w.length > 5)  { v.add(w.slice(0, -3)); v.add(w.slice(0, -3) + "e"); }
          if (w.endsWith("tion") && w.length > 6)  { v.add(w.slice(0, -4)); v.add(w.slice(0, -4) + "e"); }
          if (w.endsWith("tions")&& w.length > 7)  { v.add(w.slice(0, -5)); v.add(w.slice(0, -5) + "e"); }
          if (w.endsWith("ies")  && w.length > 5)  { v.add(w.slice(0, -3) + "y"); v.add(w.slice(0, -3) + "ie"); }
          if (w.endsWith("ves")  && w.length > 5)  { v.add(w.slice(0, -3) + "f"); v.add(w.slice(0, -3) + "fe"); }
          if (w.endsWith("ers")  && w.length > 5)  { v.add(w.slice(0, -3)); v.add(w.slice(0, -2)); }
          if (w.endsWith("er")   && w.length > 4)  { v.add(w.slice(0, -2)); }
          if (w.endsWith("ed")   && w.length > 4)  { v.add(w.slice(0, -2)); v.add(w.slice(0, -1)); }
          if (w.endsWith("es")   && w.length > 4)  { v.add(w.slice(0, -2)); v.add(w.slice(0, -1)); }
          if (w.endsWith("s")    && w.length > 4)  { v.add(w.slice(0, -1)); }
          if (w.endsWith("ly")   && w.length > 5)  { v.add(w.slice(0, -2)); v.add(w.slice(0, -2) + "le"); }
          return [...v];
        };

        // n-gram pre-filter: build grams from all stem variants of all words,
        // match against BOTH name and folder to widen the candidate pool.
        const getGrams = (word: string): string[] => {
          const n = word.length >= 8 ? 4 : word.length >= 5 ? 3 : word.length;
          if (word.length <= n) return [word];
          const grams: string[] = [];
          for (let i = 0; i <= word.length - n; i++) grams.push(word.slice(i, i + n));
          return grams;
        };

        const allStemWords = [...new Set(words.flatMap(getStems))];
        const allGrams = [...new Set(allStemWords.flatMap(getGrams))];
        const orClauses = allGrams.map(() => "LOWER(name) LIKE LOWER(?) OR LOWER(folder) LIKE LOWER(?)").join(" OR ");
        const orParams = allGrams.flatMap((g) => [`%${g}%`, `%${g}%`]);

        const FUZZY_CAP = 50_000;
        const preFilterWhere = conditions.length > 0
          ? `WHERE ${conditions.join(" AND ")} AND (${orClauses})`
          : `WHERE ${orClauses}`;

        let candidates = db
          .prepare(`SELECT * FROM file_index ${preFilterWhere} ORDER BY ${sortCol} ${order} LIMIT ${FUZZY_CAP}`)
          .all(...params, ...orParams) as FileRow[];

        if (candidates.length === 0) {
          candidates = db
            .prepare(`SELECT * FROM file_index ${where} ORDER BY ${sortCol} ${order} LIMIT ${FUZZY_CAP}`)
            .all(...params) as FileRow[];
        }

        // Fuse searches both name and folder (weighted: name > folder)
        // threshold 0.3: tight enough to reject unrelated words that share a
        // short substring (e.g. "volume" matching "lumber" via "lum") while
        // still accepting single-character typos in typical filenames.
        // minMatchCharLength: require at least 70% of the query word to match
        // contiguously, which eliminates 3-gram false positives on short words.
        const minMatch = Math.max(3, Math.ceil(q.length * 0.7));
        const fuse = new Fuse(candidates, {
          keys: [{ name: "name", weight: 0.75 }, { name: "folder", weight: 0.25 }],
          threshold: 0.3,
          minMatchCharLength: minMatch,
          includeScore: true,
          ignoreLocation: true,
        });

        // For each original word, search all its stem variants and keep the
        // best (lowest) score per file across all variants.
        const wordScoreMaps: Map<number, number>[] = words.map((word) => {
          const variants = getStems(word);
          const bestScore = new Map<number, number>();
          for (const variant of variants) {
            for (const hit of fuse.search(variant)) {
              const id = (hit.item as FileRow).id;
              const sc = hit.score ?? 1;
              if (!bestScore.has(id) || sc < bestScore.get(id)!) bestScore.set(id, sc);
            }
          }
          return bestScore;
        });

        // Keep only files that matched every word (intersection)
        const firstMap = wordScoreMaps[0];
        const matchedIds = new Set(
          [...firstMap.keys()].filter(id => wordScoreMaps.every(m => m.has(id)))
        );

        // Combined score = sum of per-word best scores; sort ascending (lower = better)
        const idToCombinedScore = new Map<number, number>();
        for (const id of matchedIds) {
          idToCombinedScore.set(id, wordScoreMaps.reduce((sum, m) => sum + (m.get(id) ?? 1), 0));
        }

        const candidateById = new Map(candidates.map(f => [f.id, f]));
        allFiles = [...matchedIds]
          .sort((a, b) => (idToCombinedScore.get(a) ?? 1) - (idToCombinedScore.get(b) ?? 1))
          .map(id => candidateById.get(id))
          .filter((f): f is FileRow => f !== undefined);
      }
    } else {
      allFiles = db.prepare(`SELECT * FROM file_index ${where} ORDER BY ${sortCol} ${order}`).all(...params) as FileRow[];
    }

    const total = allFiles.length;
    const paged = allFiles.slice(offset, offset + limitNum);

    res.json({ files: paged.map(formatFileResult), total, page: pageNum, limit: limitNum, fuzzy: fuzzyUsed, synonym_types: effectiveTypes !== types ? effectiveTypes : undefined });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/querymindr/more-like-this", async (req: Request, res: Response) => {
  try {
    const { file_id, limit: limitRaw = 20 } = req.body;
    const limitNum = Math.min(50, Math.max(1, parseInt(String(limitRaw))));

    const file = db.prepare("SELECT * FROM file_index WHERE id = ?").get(file_id) as FileRow | undefined;
    if (!file) return res.status(404).json({ files: [], total: 0, page: 1, limit: limitNum });

    const allFiles = db.prepare("SELECT id, path, name, extension, folder, size_bytes, modified_at FROM file_index WHERE id != ? LIMIT 2000").all(file_id) as FileRow[];
    const fuse = new Fuse(allFiles, { keys: ["name", "folder", "extension"], threshold: 0.5 });
    const results = fuse.search(file.name).slice(0, limitNum).map((r) => r.item);
    const ids = results.map((r) => r.id);
    const full = ids.length > 0 ? (db.prepare(`SELECT * FROM file_index WHERE id IN (${ids.map(() => "?").join(",")})`).all(...ids) as FileRow[]) : [];
    return res.json({ files: full.map(formatFileResult), total: full.length, page: 1, limit: limitNum });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.delete("/querymindr/files", async (req: Request, res: Response) => {
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

router.post("/querymindr/open", async (req: Request, res: Response) => {
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

router.get("/querymindr/duplicates", async (req: Request, res: Response) => {
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

router.post("/querymindr/index/start", async (req: Request, res: Response) => {
  try {
    const rootDirs = getRootDirs();
    const label = rootDirsLabel(rootDirs);

    if (rootDirs.length === 0) {
      return res.status(400).json({ error: "No folders have been added. Go to Settings and add at least one folder or drive to index." });
    }

    const missingDirs = rootDirs.filter(d => !fs.existsSync(d));
    if (missingDirs.length > 0) {
      const list = missingDirs.map(d => `• ${d}`).join("\n");
      return res.status(400).json({ error: `The following folder${missingDirs.length > 1 ? "s" : ""} could not be found — make sure the drive is connected and the path is correct:\n${list}` });
    }

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

router.get("/querymindr/index/status", async (req: Request, res: Response) => {
  try {
    const job = db.prepare("SELECT * FROM indexing_jobs ORDER BY id DESC LIMIT 1").get() as JobRow | undefined;
    if (!job) return res.json({ status: "idle", type: null, root_dir: null, total_files: 0, processed_files: 0, started_at: null, completed_at: null, error_message: null });
    res.json({ status: job.status, type: job.type ?? "full", root_dir: job.root_dir, total_files: job.total_files, processed_files: job.processed_files, started_at: job.started_at, completed_at: job.completed_at, error_message: job.error_message });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/querymindr/index/incremental", async (req: Request, res: Response) => {
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

router.get("/querymindr/folder-search", async (req: Request, res: Response) => {
  try {
    const { q = "" } = req.query as Record<string, string>;
    if (!q.trim()) return res.json({ folders: [] });

    const words = q.trim().split(/\s+/).filter(Boolean);

    // Step 1: Broad SQL pre-filter — any folder path containing all words anywhere.
    // This uses SQLite's LIKE which can leverage indexes to narrow the result set fast.
    const broadClauses = words.map(() => "LOWER(folder) LIKE LOWER(?)");
    const broadParams = words.map((w) => `%${w}%`);
    const rows = db.prepare(
      `SELECT DISTINCT folder FROM file_index WHERE ${broadClauses.join(" AND ")} ORDER BY LENGTH(folder), folder LIMIT 10000`
    ).all(...broadParams) as { folder: string }[];

    // Step 2: JS filter — keep only folders whose NAME (last segment after final '/')
    // contains all query words. This eliminates false positives like a numeric folder
    // "114767" matching "76" as a substring while "trombones" appears in an ancestor.
    // ORDER BY LENGTH(folder) in SQL means shallower (canonical) folders rank first.
    const matches = rows
      .filter(({ folder }) => {
        const name = folder.split("/").pop() ?? folder;
        return words.every((w) => name.toLowerCase().includes(w.toLowerCase()));
      })
      .map((r) => r.folder)
      .slice(0, 50);

    res.json({ folders: matches });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/querymindr/folders", async (req: Request, res: Response) => {
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

router.get("/querymindr/stats", async (req: Request, res: Response) => {
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
router.get("/querymindr/app-config", (_req: Request, res: Response) => {
  const config = readConfigFile();
  res.json({
    auto_index_interval_hours: config.auto_index_interval_hours ?? 12,
    checksum_limit_mb: config.checksum_limit_mb ?? 100,
    root_dirs: getRootDirs(),
  });
});

router.post("/querymindr/app-config", (req: Request, res: Response) => {
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

// ---------------------------------------------------------------------------
// License / trial endpoints
// ---------------------------------------------------------------------------

const TRIAL_DAYS = 7;
const TRIAL_FILE = path.join(os.homedir(), ".config", "querymindr", ".trial");

function getInstallDate(): Date {
  // Prefer the earlier of two stored dates: DB and hidden file.
  // This means resetting one doesn't reset the trial.
  const dates: Date[] = [];

  const dbVal = kvGet("install_date");
  if (dbVal) dates.push(new Date(dbVal));

  try {
    const fileVal = fs.readFileSync(TRIAL_FILE, "utf8").trim();
    if (fileVal) dates.push(new Date(fileVal));
  } catch { }

  if (dates.length === 0) {
    // First run — record install date in both places
    const now = new Date().toISOString();
    kvSet("install_date", now);
    try { fs.writeFileSync(TRIAL_FILE, now, { mode: 0o600 }); } catch { }
    return new Date(now);
  }

  // Use the earliest recorded date
  return new Date(Math.min(...dates.map(d => d.getTime())));
}

function getLicenseStatus(): { licensed: boolean; daysRemaining: number; installDate: string } {
  const key = kvGet("license_key");
  if (key && validateKey(key)) {
    return { licensed: true, daysRemaining: 999, installDate: getInstallDate().toISOString() };
  }

  const installDate = getInstallDate();
  const msPerDay = 1000 * 60 * 60 * 24;
  const elapsed = Math.floor((Date.now() - installDate.getTime()) / msPerDay);
  const daysRemaining = Math.max(0, TRIAL_DAYS - elapsed);

  return { licensed: false, daysRemaining, installDate: installDate.toISOString() };
}

// GET /querymindr/license/status
router.get("/querymindr/license/status", (_req: Request, res: Response) => {
  res.json(getLicenseStatus());
});

// POST /querymindr/license/activate
router.post("/querymindr/license/activate", (req: Request, res: Response) => {
  const { key } = req.body as { key?: string };
  if (!key || typeof key !== "string") {
    res.status(400).json({ ok: false, message: "No key provided." });
    return;
  }
  if (!validateKey(key.trim())) {
    res.status(400).json({ ok: false, message: "Invalid license key. Please check for typos and try again." });
    return;
  }
  kvSet("license_key", key.trim().toUpperCase());
  res.json({ ok: true, message: "License activated. Thank you!" });
});

export default router;
