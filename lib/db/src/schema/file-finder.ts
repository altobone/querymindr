import { pgTable, serial, text, bigint, timestamp, integer } from "drizzle-orm/pg-core";
import { createInsertSchema } from "drizzle-zod";
import { z } from "zod/v4";

export const fileIndex = pgTable("file_index", {
  id: serial("id").primaryKey(),
  path: text("path").notNull().unique(),
  name: text("name").notNull(),
  extension: text("extension").notNull(),
  sizeBytes: bigint("size_bytes", { mode: "number" }).notNull(),
  modifiedAt: timestamp("modified_at").notNull(),
  createdAt: timestamp("created_at").notNull(),
  folder: text("folder").notNull(),
  contentText: text("content_text"),
  aiSummary: text("ai_summary"),
  checksum: text("checksum"),
  indexedAt: timestamp("indexed_at").defaultNow(),
});

export const indexingJobs = pgTable("indexing_jobs", {
  id: serial("id").primaryKey(),
  status: text("status").notNull().default("idle"),
  rootDir: text("root_dir"),
  totalFiles: integer("total_files").notNull().default(0),
  processedFiles: integer("processed_files").notNull().default(0),
  startedAt: timestamp("started_at"),
  completedAt: timestamp("completed_at"),
  errorMessage: text("error_message"),
});

export const insertFileIndexSchema = createInsertSchema(fileIndex).omit({ id: true, indexedAt: true });
export const insertIndexingJobSchema = createInsertSchema(indexingJobs).omit({ id: true });

export type FileIndex = typeof fileIndex.$inferSelect;
export type InsertFileIndex = z.infer<typeof insertFileIndexSchema>;
export type IndexingJob = typeof indexingJobs.$inferSelect;
