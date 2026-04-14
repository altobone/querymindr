import { useState } from "react";
import { useFindDuplicates, useOpenInFinder, useGetFolders } from "@workspace/api-client-react";
import { formatBytes, cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Copy, HardDrive, Maximize2, AlertCircle } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

export default function DuplicatesPage() {
  const [folderScope, setFolderScope] = useState<string>("all");
  const { data: foldersData } = useGetFolders();
  const { toast } = useToast();

  const openFinder = useOpenInFinder();

  const { data: duplicatesData, isLoading } = useFindDuplicates({
    folder_scope: folderScope !== "all" ? folderScope : undefined,
  });

  const handleOpenInFinder = (path: string) => {
    openFinder.mutate({ data: { path } }, {
      onSuccess: () => toast({ title: "Opened in Finder" }),
      onError: () => toast({ title: "Failed to open", variant: "destructive" })
    });
  };

  const groups = duplicatesData?.groups || [];
  const totalWasted = duplicatesData?.total_wasted_bytes || 0;

  return (
    <div className="flex flex-col h-full">
      <div className="border-b border-border bg-card p-6 flex flex-col gap-4 shrink-0">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-xl font-semibold tracking-tight">Duplicate Files</h2>
            <p className="text-sm text-muted-foreground mt-1">Find and clean up exact copies of files to save disk space.</p>
          </div>
          
          <div className="bg-destructive/10 text-destructive px-4 py-2 rounded-lg border border-destructive/20 flex flex-col items-end">
            <span className="text-xs font-medium uppercase tracking-wider opacity-80">Wasted Space</span>
            <span className="text-xl font-bold flex items-center gap-2">
              <HardDrive className="w-5 h-5" />
              {formatBytes(totalWasted)}
            </span>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <Select value={folderScope} onValueChange={setFolderScope}>
            <SelectTrigger className="w-[250px] h-10">
              <SelectValue placeholder="Scan all folders" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All folders</SelectItem>
              {foldersData?.folders.map(f => (
                <SelectItem key={f} value={f}>{f}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <ScrollArea className="flex-1 bg-background p-6">
        {isLoading && (
          <div className="text-center py-20 text-muted-foreground">
            Scanning for duplicates...
          </div>
        )}

        {!isLoading && groups.length === 0 && (
          <div className="text-center py-20 flex flex-col items-center justify-center">
            <div className="w-16 h-16 rounded-full bg-secondary flex items-center justify-center mb-4 text-muted-foreground">
              <Copy className="w-8 h-8" />
            </div>
            <h3 className="text-lg font-medium">No duplicates found</h3>
            <p className="text-muted-foreground mt-1">Your drive is perfectly clean.</p>
          </div>
        )}

        <div className="space-y-6 max-w-4xl mx-auto pb-10">
          {groups.map((group, index) => (
            <div key={group.checksum} className="bg-card border border-border rounded-xl overflow-hidden shadow-sm">
              <div className="bg-secondary/50 px-4 py-3 border-b border-border flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="bg-background text-foreground text-xs font-medium px-2 py-1 rounded border">
                    Set {index + 1}
                  </div>
                  <span className="text-sm font-medium text-muted-foreground font-mono truncate max-w-[200px]">
                    {group.checksum.substring(0, 8)}...
                  </span>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <span className="font-medium text-destructive">{formatBytes(group.wasted_bytes)} wasted</span>
                  <span className="text-muted-foreground">&bull; {group.files.length} copies</span>
                </div>
              </div>
              <div className="divide-y divide-border">
                {group.files.map((file, fileIndex) => (
                  <div key={file.id} className="p-4 flex items-center justify-between hover:bg-secondary/20 transition-colors">
                    <div className="min-w-0 flex-1 pr-4">
                      <div className="flex items-center gap-2 mb-1">
                        {fileIndex === 0 ? (
                          <span className="text-[10px] font-bold uppercase tracking-wider text-primary bg-primary/10 px-1.5 py-0.5 rounded">Original</span>
                        ) : (
                          <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-secondary px-1.5 py-0.5 rounded">Copy</span>
                        )}
                        <p className="text-sm font-medium truncate">{file.name}</p>
                      </div>
                      <p className="text-xs text-muted-foreground font-mono truncate bg-secondary/50 inline-block px-1.5 py-0.5 rounded">
                        {file.path}
                      </p>
                    </div>
                    <Button 
                      variant="outline" 
                      size="sm"
                      onClick={() => handleOpenInFinder(file.path)}
                      className="shrink-0"
                    >
                      <Maximize2 className="w-3.5 h-3.5 mr-2" />
                      Reveal
                    </Button>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </ScrollArea>
    </div>
  );
}
