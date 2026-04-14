import { Router, IRouter, Request, Response } from "express";
import { exec } from "child_process";
import { promisify } from "util";
import * as fs from "fs";
import * as path from "path";
import * as crypto from "crypto";
import Anthropic from "@anthropic-ai/sdk";
import fg from "fast-glob";
import Fuse from "fuse.js";
import mime from "mime-types";
import { db } from "@workspace/db";
import { fileIndex, indexingJobs } from "@workspace/db";
import { eq, like, gte, lte, and, sql, desc, asc, isNotNull } from "drizzle-orm";

const router: IRouter = Router();
const execAsync = promisify(exec);

const ROOT_DIR = process.env.ROOT_DIR || process.cwd();
const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;

const VALID_MODELS = ["claude-haiku-4-5", "claude-sonnet-4-5"];
const DEFAULT_MODEL = "claude-haiku-4-5";

let isIndexingRunning = false;

function getAnthropicClient() {
  if (!ANTHROPIC_API_KEY) return null;
  return new Anthropic({ apiKey: ANTHROPIC_API_KEY });
}

function resolveModel(requested?: string | null): string {
  if (requested && VALID_MODELS.includes(requested)) return requested;
  return DEFAULT_MODEL;
}

function formatFileResult(f: typeof fileIndex.$inferSelect) {
  return {
    id: f.id,
    path: f.path,
    name: f.name,
    extension: f.extension,
    size_bytes: f.sizeBytes,
    modified_at: f.modifiedAt?.toISOString() ?? "",
    created_at: f.createdAt?.toISOString() ?? "",
    folder: f.folder,
    ai_summary: f.aiSummary ?? null,
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
      const data = await pdfParse(buffer);
      return data.text?.slice(0, 5000) ?? "";
    }

    if (ext === ".docx" || ext === ".doc") {
      const mammoth = await import("mammoth");
      const result = await mammoth.extractRawText({ path: filePath });
      return result.value?.slice(0, 5000) ?? "";
    }

    const textExtensions = [
      ".txt", ".md", ".markdown", ".js", ".ts", ".jsx", ".tsx",
      ".py", ".rb", ".go", ".java", ".c", ".cpp", ".h", ".css",
      ".html", ".xml", ".json", ".yaml", ".yml", ".sh", ".csv",
    ];
    if (textExtensions.includes(ext)) {
      const content = fs.readFileSync(filePath, "utf-8");
      return content.slice(0, 5000);
    }
  } catch {
  }
  return "";
}

async function computeChecksum(filePath: string): Promise<string> {
  return new Promise((resolve, reject) => {
    try {
      const hash = crypto.createHash("md5");
      const stream = fs.createReadStream(filePath);
      stream.on("data", (chunk) => hash.update(chunk));
      stream.on("end", () => resolve(hash.digest("hex")));
      stream.on("error", (err) => reject(err));
    } catch (err) {
      reject(err);
    }
  });
}

async function runIndexing(rootDir: string, jobId: number) {
  isIndexingRunning = true;
  try {
    await db.update(indexingJobs).set({ status: "running", startedAt: new Date() }).where(eq(indexingJobs.id, jobId));

    const patterns = ["**/*"];
    const entries = await fg(patterns, {
      cwd: rootDir,
      absolute: true,
      onlyFiles: true,
      followSymbolicLinks: false,
      ignore: ["**/node_modules/**", "**/.git/**", "**/.DS_Store"],
      dot: false,
    });

    const totalFiles = entries.length;
    await db.update(indexingJobs).set({ totalFiles }).where(eq(indexingJobs.id, jobId));

    let processed = 0;
    const batchSize = 50;

    for (let i = 0; i < entries.length; i += batchSize) {
      const batch = entries.slice(i, i + batchSize);
      const rows = [];

      for (const filePath of batch) {
        try {
          const stat = fs.statSync(filePath);
          if (!stat.isFile()) continue;

          const ext = path.extname(filePath).toLowerCase();
          const name = path.basename(filePath);
          const folder = path.dirname(filePath);

          const contentText = await extractTextContent(filePath, ext);

          let checksum: string | undefined;
          try {
            if (stat.size < 500 * 1024 * 1024) {
              checksum = await computeChecksum(filePath);
            }
          } catch {
          }

          rows.push({
            path: filePath,
            name,
            extension: ext || "(no extension)",
            sizeBytes: stat.size,
            modifiedAt: stat.mtime,
            createdAt: stat.birthtime || stat.mtime,
            folder,
            contentText: contentText || null,
            aiSummary: null,
            checksum: checksum || null,
          });
        } catch {
        }
        processed++;
      }

      if (rows.length > 0) {
        await db
          .insert(fileIndex)
          .values(rows)
          .onConflictDoUpdate({
            target: fileIndex.path,
            set: {
              name: sql`excluded.name`,
              extension: sql`excluded.extension`,
              sizeBytes: sql`excluded.size_bytes`,
              modifiedAt: sql`excluded.modified_at`,
              createdAt: sql`excluded.created_at`,
              folder: sql`excluded.folder`,
              contentText: sql`excluded.content_text`,
              checksum: sql`excluded.checksum`,
              indexedAt: sql`NOW()`,
            },
          });
      }

      await db.update(indexingJobs).set({ processedFiles: processed }).where(eq(indexingJobs.id, jobId));
    }

    await db.update(indexingJobs).set({ status: "completed", completedAt: new Date(), processedFiles: processed }).where(eq(indexingJobs.id, jobId));
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : String(err);
    await db.update(indexingJobs).set({ status: "failed", errorMessage: msg, completedAt: new Date() }).where(eq(indexingJobs.id, jobId));
  } finally {
    isIndexingRunning = false;
  }
}

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

    const conditions = [];

    if (types) {
      const extList = types.split(",").map((t) => t.trim().toLowerCase()).filter(Boolean).map((t) => (t.startsWith(".") ? t : "." + t));
      if (extList.length === 1) {
        conditions.push(eq(fileIndex.extension, extList[0]));
      } else if (extList.length > 1) {
        conditions.push(sql`${fileIndex.extension} = ANY(${extList})`);
      }
    }

    if (date_from) conditions.push(gte(fileIndex.modifiedAt, new Date(date_from)));
    if (date_to) conditions.push(lte(fileIndex.modifiedAt, new Date(date_to)));
    if (size_min) conditions.push(gte(fileIndex.sizeBytes, parseInt(size_min)));
    if (size_max) conditions.push(lte(fileIndex.sizeBytes, parseInt(size_max)));
    if (folder_scope) conditions.push(like(fileIndex.folder, `${folder_scope}%`));

    const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

    let allFiles = await db.select().from(fileIndex).where(whereClause);

    if (query.trim()) {
      const fuse = new Fuse(allFiles, {
        keys: ["name", "folder", "contentText"],
        threshold: 0.4,
        includeScore: true,
      });
      const results = fuse.search(query.trim());
      allFiles = results.map((r) => r.item);
    } else {
      const sortCol = sort_by === "name" ? fileIndex.name : sort_by === "size" ? fileIndex.sizeBytes : sort_by === "type" ? fileIndex.extension : fileIndex.modifiedAt;
      const orderFn = sort_order === "asc" ? asc : desc;
      allFiles = await db.select().from(fileIndex).where(whereClause).orderBy(orderFn(sortCol));
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

    const conditions = folder_scope ? [like(fileIndex.folder, `${folder_scope}%`)] : [];
    const allFiles = await db.select({ id: fileIndex.id, path: fileIndex.path, name: fileIndex.name, extension: fileIndex.extension, folder: fileIndex.folder, sizeBytes: fileIndex.sizeBytes, modifiedAt: fileIndex.modifiedAt, aiSummary: fileIndex.aiSummary }).from(fileIndex).where(conditions.length > 0 ? and(...conditions) : undefined).limit(2000);

    if (!client) {
      const fuse = new Fuse(allFiles, { keys: ["name", "folder", "aiSummary"], threshold: 0.4 });
      const results = fuse.search(description).map((r) => r.item);
      const full = await db.select().from(fileIndex).where(sql`${fileIndex.id} = ANY(${results.slice(0, 20).map((r) => r.id)})`);
      return res.json({ files: full.map(formatFileResult), explanation: "Fuzzy search results (Claude API key not configured).", total: full.length });
    }

    const fileList = allFiles.slice(0, 1000).map((f) => `ID:${f.id} | ${f.name} | ${f.extension} | ${f.folder} | ${f.aiSummary ?? ""}`).join("\n");

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 1024,
      messages: [{
        role: "user",
        content: `You are a file search assistant. The user is looking for: "${description}"\n\nHere is a list of files (format: ID | name | extension | folder | summary):\n${fileList}\n\nReturn a JSON object with:\n- "ids": array of up to 20 file IDs that best match the description, ordered by relevance\n- "explanation": a one-sentence explanation of what you found\n\nReturn ONLY valid JSON, no other text.`,
      }],
    });

    const text = response.content[0].type === "text" ? response.content[0].text : "{}";
    let parsed: { ids?: number[]; explanation?: string } = {};
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { ids: [], explanation: "Could not parse AI response." };
    }

    const matchedIds = parsed.ids ?? [];
    const matchedFiles = matchedIds.length > 0
      ? await db.select().from(fileIndex).where(sql`${fileIndex.id} = ANY(${matchedIds})`)
      : [];

    const sortedFiles = matchedIds.map((id) => matchedFiles.find((f) => f.id === id)).filter(Boolean) as typeof matchedFiles;

    res.json({ files: sortedFiles.map(formatFileResult), explanation: parsed.explanation ?? "", total: sortedFiles.length });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/ai-summarize", async (req: Request, res: Response) => {
  try {
    const { file_id, model } = req.body;
    const client = getAnthropicClient();

    const [file] = await db.select().from(fileIndex).where(eq(fileIndex.id, file_id));
    if (!file) return res.status(404).json({ error: "File not found" });

    if (!client) {
      const fallback = `${file.name} — ${file.extension} file, ${(file.sizeBytes / 1024).toFixed(0)} KB, last modified ${file.modifiedAt?.toLocaleDateString()}`;
      await db.update(fileIndex).set({ aiSummary: fallback }).where(eq(fileIndex.id, file_id));
      return res.json({ summary: fallback, file_id });
    }

    let content = file.contentText ?? "";
    if (!content) {
      content = await extractTextContent(file.path, file.extension);
    }

    const prompt = content
      ? `Summarize this file in 1-2 sentences. File: "${file.name}" (${file.extension})\n\nContent:\n${content.slice(0, 3000)}`
      : `Describe what this file likely contains based on its name and type. File: "${file.name}" (${file.extension}), size: ${(file.sizeBytes / 1024).toFixed(0)} KB, folder: "${file.folder}"`;

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 200,
      messages: [{ role: "user", content: prompt }],
    });

    const summary = response.content[0].type === "text" ? response.content[0].text : "";
    await db.update(fileIndex).set({ aiSummary: summary }).where(eq(fileIndex.id, file_id));

    res.json({ summary, file_id });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.post("/file-finder/more-like-this", async (req: Request, res: Response) => {
  try {
    const { file_id, limit: limitRaw = 20, model } = req.body;
    const limitNum = Math.min(50, Math.max(1, parseInt(String(limitRaw))));

    const [file] = await db.select().from(fileIndex).where(eq(fileIndex.id, file_id));
    if (!file) return res.status(404).json({ files: [], total: 0, page: 1, limit: limitNum });

    const client = getAnthropicClient();
    const allFiles = await db.select({ id: fileIndex.id, path: fileIndex.path, name: fileIndex.name, extension: fileIndex.extension, folder: fileIndex.folder, sizeBytes: fileIndex.sizeBytes, modifiedAt: fileIndex.modifiedAt, aiSummary: fileIndex.aiSummary }).from(fileIndex).where(sql`${fileIndex.id} != ${file_id}`).limit(2000);

    if (!client) {
      const fuse = new Fuse(allFiles, { keys: ["name", "folder", "extension", "aiSummary"], threshold: 0.5 });
      const results = fuse.search(file.name).slice(0, limitNum).map((r) => r.item);
      const full = await db.select().from(fileIndex).where(sql`${fileIndex.id} = ANY(${results.map((r) => r.id)})`);
      return res.json({ files: full.map(formatFileResult), total: full.length, page: 1, limit: limitNum });
    }

    const fileList = allFiles.slice(0, 800).map((f) => `ID:${f.id} | ${f.name} | ${f.extension} | ${f.folder} | ${f.aiSummary ?? ""}`).join("\n");

    const response = await client.messages.create({
      model: resolveModel(model),
      max_tokens: 512,
      messages: [{
        role: "user",
        content: `Find files similar to: "${file.name}" (${file.extension}, in folder: ${file.folder}, summary: "${file.aiSummary ?? "none"}")\n\nFile list:\n${fileList}\n\nReturn JSON: {"ids": [array of up to ${limitNum} most similar file IDs]}. Return ONLY valid JSON.`,
      }],
    });

    const text = response.content[0].type === "text" ? response.content[0].text : "{}";
    let parsed: { ids?: number[] } = {};
    try { parsed = JSON.parse(text); } catch { parsed = { ids: [] }; }

    const matchedIds = parsed.ids ?? [];
    const matchedFiles = matchedIds.length > 0 ? await db.select().from(fileIndex).where(sql`${fileIndex.id} = ANY(${matchedIds})`) : [];

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
    const conditions = [isNotNull(fileIndex.checksum)];
    if (folder_scope) conditions.push(like(fileIndex.folder, `${folder_scope}%`));

    const groups = await db
      .select({ checksum: fileIndex.checksum, count: sql<number>`COUNT(*)`, totalSize: sql<number>`SUM(${fileIndex.sizeBytes})` })
      .from(fileIndex)
      .where(and(...conditions))
      .groupBy(fileIndex.checksum)
      .having(sql`COUNT(*) > 1`);

    let totalWastedBytes = 0;
    const result = [];

    for (const group of groups) {
      if (!group.checksum) continue;
      const files = await db.select().from(fileIndex).where(eq(fileIndex.checksum, group.checksum));
      const wastedBytes = (files[0]?.sizeBytes ?? 0) * (files.length - 1);
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
      const [currentJob] = await db.select().from(indexingJobs).orderBy(desc(indexingJobs.id)).limit(1);
      return res.json({
        status: "running",
        root_dir: currentJob?.rootDir ?? rootDir,
        total_files: currentJob?.totalFiles ?? 0,
        processed_files: currentJob?.processedFiles ?? 0,
        started_at: currentJob?.startedAt?.toISOString() ?? null,
        completed_at: null,
        error_message: null,
      });
    }

    const [job] = await db.insert(indexingJobs).values({ status: "running", rootDir, totalFiles: 0, processedFiles: 0, startedAt: new Date() }).returning();

    runIndexing(rootDir, job.id).catch(() => {});

    res.json({
      status: "running",
      root_dir: rootDir,
      total_files: 0,
      processed_files: 0,
      started_at: job.startedAt?.toISOString() ?? null,
      completed_at: null,
      error_message: null,
    });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/index/status", async (req: Request, res: Response) => {
  try {
    const [job] = await db.select().from(indexingJobs).orderBy(desc(indexingJobs.id)).limit(1);
    if (!job) {
      return res.json({ status: "idle", root_dir: null, total_files: 0, processed_files: 0, started_at: null, completed_at: null, error_message: null });
    }
    res.json({
      status: job.status,
      root_dir: job.rootDir,
      total_files: job.totalFiles,
      processed_files: job.processedFiles,
      started_at: job.startedAt?.toISOString() ?? null,
      completed_at: job.completedAt?.toISOString() ?? null,
      error_message: job.errorMessage,
    });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

router.get("/file-finder/folders", async (req: Request, res: Response) => {
  try {
    const rows = await db.selectDistinct({ folder: fileIndex.folder }).from(fileIndex).limit(200);
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
    const [totals] = await db.select({ count: sql<number>`COUNT(*)`, totalSize: sql<number>`SUM(${fileIndex.sizeBytes})` }).from(fileIndex);

    const typeRows = await db
      .select({ extension: fileIndex.extension, count: sql<number>`COUNT(*)`, totalSize: sql<number>`SUM(${fileIndex.sizeBytes})` })
      .from(fileIndex)
      .groupBy(fileIndex.extension)
      .orderBy(desc(sql`COUNT(*)`))
      .limit(50);

    const [lastJob] = await db.select().from(indexingJobs).where(eq(indexingJobs.status, "completed")).orderBy(desc(indexingJobs.completedAt)).limit(1);

    res.json({
      total_files: Number(totals?.count ?? 0),
      total_size_bytes: Number(totals?.totalSize ?? 0),
      file_types: typeRows.map((r) => ({ extension: r.extension, count: Number(r.count), total_size_bytes: Number(r.totalSize ?? 0) })),
      last_indexed: lastJob?.completedAt?.toISOString() ?? null,
      root_dir: ROOT_DIR,
    });
  } catch (err: unknown) {
    res.status(500).json({ error: err instanceof Error ? err.message : String(err) });
  }
});

export default router;
