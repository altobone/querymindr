import { useState, useMemo } from "react";
import { useFindDuplicates, useOpenInFinder } from "@workspace/api-client-react";
import { formatBytes, formatDate } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Copy, HardDrive, Trash2, Maximize2, Search, Zap } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

async function deleteFiles(paths: string[]): Promise<void> {
  const res = await fetch("/api/querymindr/files", {
    method: "DELETE",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ paths }),
  });
  if (!res.ok) throw new Error("Delete failed");
}

export default function DuplicatesPage() {
  const [filter, setFilter] = useState("");
  const [autoPattern, setAutoPattern] = useState("");
  const [deletedPaths, setDeletedPaths] = useState<Set<string>>(new Set());
  const [deleting, setDeleting] = useState<Set<string>>(new Set());
  const [confirming, setConfirming] = useState(false);
  const { toast } = useToast();
  const openFinder = useOpenInFinder();

  const { data: duplicatesData, isLoading } = useFindDuplicates({});

  const groups = useMemo(() => {
    const allGroups = duplicatesData?.groups || [];
    return allGroups
      .map(group => ({
        ...group,
        files: group.files
          .filter(f => !deletedPaths.has(f.path))
          .sort((a, b) => {
            const da = a.created_at ? new Date(a.created_at).getTime() : 0;
            const db_ = b.created_at ? new Date(b.created_at).getTime() : 0;
            return db_ - da;
          }),
      }))
      .filter(group => group.files.length > 1)
      .filter(group => {
        if (!filter.trim()) return true;
        const q = filter.toLowerCase();
        return group.files.some(f => f.path.toLowerCase().includes(q));
      });
  }, [duplicatesData, deletedPaths, filter]);

  const totalWasted = useMemo(
    () => groups.reduce((sum, g) => sum + (g.files[0]?.size_bytes ?? 0) * (g.files.length - 1), 0),
    [groups]
  );

  const autoPatternMatches = useMemo(() => {
    if (!autoPattern.trim()) return [];
    const q = autoPattern.toLowerCase();
    const toDelete: string[] = [];
    for (const group of (duplicatesData?.groups || []).map(g => ({ ...g, files: g.files.filter(f => !deletedPaths.has(f.path)) })).filter(g => g.files.length > 1)) {
      const copies = group.files.slice(1).filter(f => f.path.toLowerCase().includes(q));
      toDelete.push(...copies.map(f => f.path));
    }
    return toDelete;
  }, [autoPattern, duplicatesData, deletedPaths]);

  const handleDelete = async (paths: string[]) => {
    setDeleting(prev => new Set([...prev, ...paths]));
    try {
      await deleteFiles(paths);
      setDeletedPaths(prev => new Set([...prev, ...paths]));
      toast({ title: `Deleted ${paths.length} file${paths.length > 1 ? "s" : ""}` });
    } catch {
      toast({ title: "Delete failed", variant: "destructive" });
    } finally {
      setDeleting(prev => {
        const next = new Set(prev);
        paths.forEach(p => next.delete(p));
        return next;
      });
    }
  };

  return (
    <div className="flex flex-col h-full">
      <div className="border-b border-border bg-card p-6 flex flex-col gap-4 shrink-0">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-xl font-semibold tracking-tight" style={{ color: "#e8ff47" }}>Duplicate Files</h2>
            <p className="text-sm text-muted-foreground mt-1">
              {isLoading ? "Scanning…" : `${groups.length} duplicate sets · `}
              {!isLoading && <span className="text-destructive font-medium">{formatBytes(totalWasted)} wasted</span>}
            </p>
          </div>
          <div className="bg-destructive/10 text-destructive px-4 py-2 rounded-lg border border-destructive/20 flex flex-col items-end">
            <span className="text-xs font-medium uppercase tracking-wider opacity-80">Wasted Space</span>
            <span className="text-xl font-bold flex items-center gap-2">
              <HardDrive className="w-5 h-5" />
              {formatBytes(totalWasted)}
            </span>
          </div>
        </div>

        {/* Filter */}
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            className="pl-9"
            placeholder="Filter by path keyword (e.g. cache, cleanup, 2019)…"
            value={filter}
            onChange={e => setFilter(e.target.value)}
          />
        </div>

        {/* Auto-clean by pattern */}
        <div className="bg-secondary/40 border border-border rounded-lg p-4 flex flex-col gap-3">
          <div className="flex items-center gap-2">
            <Zap className="w-4 h-4 text-yellow-500" />
            <span className="text-sm font-semibold">Bulk Auto-Clean</span>
            <span className="text-xs text-muted-foreground">— keep the original, delete all copies whose path contains:</span>
          </div>
          <div className="flex gap-2">
            <Input
              placeholder="e.g. Cleanup, cache, .tmp, Old Projects…"
              value={autoPattern}
              onChange={e => { setAutoPattern(e.target.value); setConfirming(false); }}
              className="flex-1"
            />
            {!confirming ? (
              <Button
                variant="outline"
                disabled={autoPatternMatches.length === 0}
                onClick={() => setConfirming(true)}
              >
                {autoPatternMatches.length > 0
                  ? `Preview: ${autoPatternMatches.length} files`
                  : "No matches"}
              </Button>
            ) : (
              <div className="flex gap-2 items-center">
                <span className="text-sm text-destructive font-medium">Delete {autoPatternMatches.length} files?</span>
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={deleting.size > 0}
                  onClick={async () => {
                    setConfirming(false);
                    setAutoPattern("");
                    await handleDelete(autoPatternMatches);
                  }}
                >
                  Confirm
                </Button>
                <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>Cancel</Button>
              </div>
            )}
          </div>
          {autoPatternMatches.length > 0 && !confirming && (
            <p className="text-xs text-muted-foreground">
              Will delete {autoPatternMatches.length} copy files that contain "{autoPattern}" in their path. Originals are always kept.
            </p>
          )}
        </div>
      </div>

      <ScrollArea className="flex-1 bg-background p-6">
        {isLoading && (
          <div className="text-center py-20 text-muted-foreground">Scanning for duplicates…</div>
        )}
        {!isLoading && groups.length === 0 && (
          <div className="text-center py-20 flex flex-col items-center justify-center">
            <div className="w-16 h-16 rounded-full bg-secondary flex items-center justify-center mb-4 text-muted-foreground">
              <Copy className="w-8 h-8" />
            </div>
            <h3 className="text-lg font-medium">No duplicates found</h3>
            <p className="text-muted-foreground mt-1">
              {filter ? `No results matching "${filter}"` : "Your drive is clean."}
            </p>
          </div>
        )}

        <div className="space-y-4 max-w-4xl mx-auto pb-10">
          {groups.map((group, index) => {
            const copies = group.files.slice(1);
            const copyPaths = copies.map(f => f.path);
            const allDeleting = copyPaths.every(p => deleting.has(p));
            return (
              <div key={group.checksum} className="bg-card border border-border rounded-xl overflow-hidden shadow-sm">
                <div className="bg-secondary/50 px-4 py-3 border-b border-border flex items-center justify-between gap-2">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="bg-background text-foreground text-xs font-medium px-2 py-1 rounded border shrink-0">
                      Set {index + 1}
                    </div>
                    <span className="text-xs text-muted-foreground font-mono truncate">
                      {group.checksum.substring(0, 12)}…
                    </span>
                    <span className="text-sm text-destructive font-medium shrink-0">
                      {formatBytes(group.wasted_bytes)} wasted
                    </span>
                  </div>
                  <Button
                    variant="destructive"
                    size="sm"
                    disabled={allDeleting || copies.length === 0}
                    onClick={() => handleDelete(copyPaths)}
                    className="shrink-0"
                  >
                    <Trash2 className="w-3.5 h-3.5 mr-1.5" />
                    Keep Newest · Delete {copies.length} Older
                  </Button>
                </div>

                <div className="divide-y divide-border">
                  {group.files.map((file, fileIndex) => (
                    <div key={file.id} className="p-4 flex items-start gap-3 hover:bg-secondary/20 transition-colors">
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 mb-1.5 flex-wrap">
                          {fileIndex === 0 ? (
                            <span className="text-[10px] font-bold uppercase tracking-wider text-primary bg-primary/10 px-1.5 py-0.5 rounded shrink-0">Newest</span>
                          ) : (
                            <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-secondary px-1.5 py-0.5 rounded shrink-0">Older</span>
                          )}
                          <p className="text-sm font-medium truncate">{file.name}</p>
                          <span className="text-xs text-muted-foreground shrink-0">{formatBytes(file.size_bytes)}</span>
                          {file.created_at && (
                            <span className="text-xs text-muted-foreground shrink-0 bg-secondary/60 px-1.5 py-0.5 rounded">
                              Created {formatDate(file.created_at)}
                            </span>
                          )}
                        </div>
                        <p className="text-xs text-muted-foreground font-mono break-all bg-secondary/50 px-2 py-1.5 rounded leading-relaxed">
                          {file.path}
                        </p>
                      </div>
                      <div className="flex flex-col gap-1.5 shrink-0 pt-1">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openFinder.mutate({ data: { path: file.path } }, { onSuccess: () => toast({ title: "Opened in Finder" }) })}
                        >
                          <Maximize2 className="w-3.5 h-3.5 mr-1.5" />
                          Reveal
                        </Button>
                        {fileIndex > 0 && (
                          <Button
                            variant="destructive"
                            size="sm"
                            disabled={deleting.has(file.path)}
                            onClick={() => handleDelete([file.path])}
                          >
                            <Trash2 className="w-3.5 h-3.5 mr-1.5" />
                            {deleting.has(file.path) ? "Deleting…" : "Delete"}
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </ScrollArea>
    </div>
  );
}
