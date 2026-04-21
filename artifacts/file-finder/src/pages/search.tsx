import { useState, useEffect } from "react";
import { useSearchFiles, useMoreLikeThis, useOpenInFinder, useGetFolders } from "@workspace/api-client-react";
import { formatBytes, formatDate, cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Search, Folder, FolderOpen, FileText, Calendar, HardDrive, File as FileIcon, X, Maximize2, Copy, Filter, Loader2 } from "lucide-react";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Separator } from "@/components/ui/separator";
import { useToast } from "@/hooks/use-toast";
import type { FileResult, SearchFilesSortBy, SearchFilesSortOrder } from "@workspace/api-client-react/src/generated/api.schemas";

export default function SearchPage() {
  const [query, setQuery] = useState("");
  const [selectedFile, setSelectedFile] = useState<FileResult | null>(null);

  // Filters
  const [folderScope, setFolderScope] = useState<string>("all");
  const [sortBy, setSortBy] = useState<SearchFilesSortBy>("date");
  const [sortOrder, setSortOrder] = useState<SearchFilesSortOrder>("desc");
  const [fileTypes, setFileTypes] = useState<string>("");
  const [sizeMin, setSizeMin] = useState<string>("");
  const [sizeMax, setSizeMax] = useState<string>("");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [folderResults, setFolderResults] = useState<string[]>([]);
  const [browseReturnQuery, setBrowseReturnQuery] = useState<string>("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [slowSearchVisible, setSlowSearchVisible] = useState(false);

  const { data: foldersData } = useGetFolders();
  const { toast } = useToast();

  const openFinder = useOpenInFinder();
  const moreLikeThis = useMoreLikeThis();

  // Debounce the file search query so it fires once after typing stops,
  // not on every keystroke. Folder search has its own 300ms debounce via useEffect.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 300);
    return () => clearTimeout(t);
  }, [query]);

  const { data: searchResults, isFetching: isSearchLoading, refetch: refetchSearch } = useSearchFiles({
    query: debouncedQuery,
    folder_scope: folderScope !== "all" ? folderScope : undefined,
    sort_by: sortBy,
    sort_order: sortOrder,
    types: fileTypes || undefined,
    size_min: sizeMin ? parseInt(sizeMin) : undefined,
    size_max: sizeMax ? parseInt(sizeMax) : undefined,
    limit: 50,
  }, { query: { enabled: debouncedQuery.length > 0 || folderScope !== "all" } });

  const [similarResults, setSimilarResults] = useState<FileResult[] | null>(null);

  // Show a hint after 1.5s of loading — exact searches finish instantly,
  // so anything slower is almost certainly a fuzzy (approximate) search.
  useEffect(() => {
    if (!isSearchLoading) { setSlowSearchVisible(false); return; }
    const t = setTimeout(() => setSlowSearchVisible(true), 1500);
    return () => clearTimeout(t);
  }, [isSearchLoading]);

  // Folder search fires reactively as the user types (debounced 300ms)
  useEffect(() => {
    if (!query.trim()) {
      setFolderResults([]);
      return;
    }
    const timer = setTimeout(() => {
      fetch(`/api/querymindr/folder-search?q=${encodeURIComponent(query.trim())}`)
        .then((r) => r.json())
        .then((data) => setFolderResults(data.folders || []))
        .catch(() => setFolderResults([]));
    }, 300);
    return () => clearTimeout(timer);
  }, [query]);

  const handleSearch = () => {
    setSimilarResults(null);
    refetchSearch();
  };

  const handleBrowseFolder = (folderPath: string) => {
    setBrowseReturnQuery(query);
    setFolderScope(folderPath);
    setQuery("");
    setFolderResults([]);
    setSimilarResults(null);
  };

  const handleExitFolderBrowse = () => {
    setFolderScope("all");
    if (browseReturnQuery) {
      setQuery(browseReturnQuery);
      setBrowseReturnQuery("");
    }
  };

  const handleOpenInFinder = (path: string) => {
    openFinder.mutate({ data: { path } }, {
      onSuccess: () => {
        toast({ title: "Opened in Finder", description: path });
      },
      onError: () => {
        toast({ title: "Failed to open", description: "Could not open file in Finder", variant: "destructive" });
      }
    });
  };

  const handleMoreLikeThis = (fileId: number) => {
    moreLikeThis.mutate({ data: { file_id: fileId, limit: 20 } }, {
      onSuccess: (data) => {
        setQuery(`Similar to file #${fileId}`);
        setSimilarResults(data.files);
      }
    });
  };

  const files = similarResults ? similarResults : (searchResults?.files || []);
  const isLoading = similarResults ? moreLikeThis.isPending : isSearchLoading;

  return (
    <div className="flex h-full w-full">
      {/* Main List */}
      <div className={cn("flex-1 flex flex-col h-full transition-all duration-300", selectedFile ? "mr-96" : "")}>
        <div className="border-b border-border bg-card p-6 flex flex-col gap-4">
          <div className="flex items-center justify-between">
            <h2 className="text-xl font-semibold tracking-tight" style={{ color: "#e8ff47" }}>Search</h2>
          </div>

          <div className="flex items-center gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <Input
                placeholder="Search by filename..."
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && handleSearch()}
                className="pl-9 bg-background h-10 border-input"
              />
            </div>
            <Select value={folderScope} onValueChange={setFolderScope}>
              <SelectTrigger className="w-[200px] h-10">
                <SelectValue placeholder="All folders" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All folders</SelectItem>
                {foldersData?.folders.map(f => (
                  <SelectItem key={f} value={f}>{f}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button onClick={handleSearch} disabled={isLoading} className="h-10 px-6 gap-2">
              {isLoading ? (
                <><Loader2 className="w-4 h-4 animate-spin" />Searching...</>
              ) : (
                <><Search className="w-4 h-4" />Search</>
              )}
            </Button>
          </div>

          {slowSearchVisible && isSearchLoading && (
            <div className="flex items-center gap-2 text-xs text-muted-foreground animate-pulse">
              <Loader2 className="w-3.5 h-3.5 animate-spin shrink-0" />
              No exact match found — searching for approximate results…
            </div>
          )}

          <div className="flex items-center gap-4 text-sm">
              <Popover open={filtersOpen} onOpenChange={setFiltersOpen}>
                <PopoverTrigger asChild>
                  <Button variant="outline" size="sm" className="h-8 gap-2 border-dashed">
                    <Filter className="w-3.5 h-3.5" />
                    Filters
                    {(fileTypes || sizeMin || sizeMax) && (
                      <span className="w-2 h-2 rounded-full bg-primary ml-1" />
                    )}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-80" align="start">
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <h4 className="font-medium text-sm leading-none">File Types</h4>
                      <p className="text-xs text-muted-foreground">Comma separated (e.g. mp4,jpg,pdf)</p>
                      <Input 
                        placeholder="pdf, txt, md" 
                        value={fileTypes} 
                        onChange={e => setFileTypes(e.target.value)} 
                        className="h-8 text-sm"
                      />
                    </div>
                    <Separator />
                    <div className="space-y-2">
                      <h4 className="font-medium text-sm leading-none">Size Range (Bytes)</h4>
                      <div className="flex items-center gap-2">
                        <Input 
                          placeholder="Min" 
                          type="number"
                          value={sizeMin}
                          onChange={e => setSizeMin(e.target.value)}
                          className="h-8 text-sm"
                        />
                        <span className="text-muted-foreground">-</span>
                        <Input 
                          placeholder="Max" 
                          type="number"
                          value={sizeMax}
                          onChange={e => setSizeMax(e.target.value)}
                          className="h-8 text-sm"
                        />
                      </div>
                    </div>
                    <Button size="sm" className="w-full" onClick={() => { handleSearch(); setFiltersOpen(false); }}>
                      Apply Filters
                    </Button>
                  </div>
                </PopoverContent>
              </Popover>

              <div className="flex items-center gap-2 ml-auto">
                <span className="text-muted-foreground">Sort by:</span>
                <Select value={sortBy} onValueChange={(v) => setSortBy(v as SearchFilesSortBy)}>
                  <SelectTrigger className="h-8 w-[120px] text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="date">Date</SelectItem>
                    <SelectItem value="name">Name</SelectItem>
                    <SelectItem value="size">Size</SelectItem>
                    <SelectItem value="type">Type</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-2">
                <span className="text-muted-foreground">Order:</span>
                <Select value={sortOrder} onValueChange={(v) => setSortOrder(v as SearchFilesSortOrder)}>
                  <SelectTrigger className="h-8 w-[120px] text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="desc">Descending</SelectItem>
                    <SelectItem value="asc">Ascending</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

          {/* Active filter chips */}
          {((folderScope !== "all") || (fileTypes || sizeMin || sizeMax)) && (
            <div className="flex flex-wrap gap-2">
              {folderScope !== "all" && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                  <Folder className="w-3 h-3 shrink-0" />
                  {folderScope.split("/").pop() ?? folderScope}
                  <button
                    onClick={() => handleOpenInFinder(folderScope)}
                    className="hover:opacity-60 transition-opacity ml-0.5"
                    aria-label="Reveal folder in Finder"
                    title="Reveal in Finder"
                  >
                    <FolderOpen className="w-3 h-3" />
                  </button>
                  <button onClick={handleExitFolderBrowse} className="hover:opacity-60 transition-opacity" aria-label="Exit folder browse">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {fileTypes && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                  <FileText className="w-3 h-3 shrink-0" />
                  Type: {fileTypes}
                  <button onClick={() => setFileTypes("")} className="hover:opacity-60 transition-opacity ml-0.5" aria-label="Remove file type filter">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {(sizeMin || sizeMax) && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                  <HardDrive className="w-3 h-3 shrink-0" />
                  Size: {sizeMin ? formatBytes(parseInt(sizeMin)) : "any"} – {sizeMax ? formatBytes(parseInt(sizeMax)) : "any"}
                  <button onClick={() => { setSizeMin(""); setSizeMax(""); }} className="hover:opacity-60 transition-opacity ml-0.5" aria-label="Remove size filter">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
            </div>
          )}

        </div>

        <ScrollArea className="flex-1 bg-background">
          <div className="p-6 space-y-6">
            {/* Fuzzy match banner */}
            {!isLoading && (searchResults as { fuzzy?: boolean })?.fuzzy && files.length > 0 && (
              <div className="flex items-center gap-2 px-3 py-2 rounded-md text-xs" style={{ color: "#e8ff47", backgroundColor: "rgba(232,255,71,0.07)", border: "1px solid rgba(232,255,71,0.25)" }}>
                <Search className="w-3.5 h-3.5 shrink-0" style={{ color: "#e8ff47" }} />
                No exact matches — showing approximate results for <span className="font-medium mx-1" style={{ color: "#e8ff47" }}>"{debouncedQuery}"</span>
              </div>
            )}

            {/* File results — always show when a search has been run */}
            {!isLoading && (debouncedQuery.length > 0 || folderScope !== "all") && (
              <div className="space-y-2">
                {(folderResults.length > 0 || files.length > 0) && (
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                    <FileText className="w-3.5 h-3.5" />
                    Files ({files.length})
                  </h3>
                )}
                {files.length === 0 && !isLoading && (debouncedQuery || folderScope !== "all") && (
                  <div className="text-center py-20 text-muted-foreground">
                    No files found matching your query.
                  </div>
                )}
            
                {files.length > 0 && (
                  <div className="space-y-1">
                    {files.map((file) => (
                      <div
                        key={file.id}
                        onClick={() => setSelectedFile(file)}
                        className={cn(
                          "flex items-center justify-between p-3 rounded-lg cursor-pointer transition-colors border border-transparent",
                          selectedFile?.id === file.id
                            ? "bg-secondary border-border"
                            : "hover:bg-secondary/50"
                        )}
                      >
                        <div className="flex items-center gap-4 min-w-0 flex-1">
                          <div className="w-10 h-10 rounded bg-card border flex items-center justify-center shrink-0 text-muted-foreground">
                            <FileIcon className="w-5 h-5" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium">{file.name}</p>
                            <p className="text-xs text-muted-foreground font-mono break-all mt-0.5 leading-relaxed">{file.path}</p>
                            <div className="flex items-center gap-3 text-xs text-muted-foreground mt-1">
                              <span>{formatDate(file.modified_at)}</span>
                              <span>&bull;</span>
                              <span>{formatBytes(file.size_bytes)}</span>
                            </div>
                          </div>
                        </div>
                        <div className="shrink-0 pl-4">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleOpenInFinder(file.path);
                            }}
                          >
                            Reveal
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Folder results */}
            {folderResults.length > 0 && (
              <div className="space-y-2">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <Folder className="w-3.5 h-3.5" />
                  Folders ({folderResults.length})
                </h3>
                <div className="space-y-1">
                  {folderResults.map((f) => {
                    const parts = f.split("/");
                    const name = parts[parts.length - 1] || f;
                    const parent = parts.slice(0, -1).join("/");
                    return (
                      <div
                        key={f}
                        className="flex items-center justify-between p-3 rounded-lg border border-border bg-card hover:bg-secondary/50 transition-colors"
                      >
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="w-9 h-9 rounded bg-primary/10 border border-primary/20 flex items-center justify-center shrink-0">
                            <Folder className="w-4 h-4 text-primary" />
                          </div>
                          <div className="min-w-0">
                            <p className="text-sm font-medium">{name}</p>
                            <p className="text-xs text-muted-foreground font-mono truncate">{parent}</p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2 shrink-0 ml-4">
                          <Button
                            size="sm"
                            variant="outline"
                            className="gap-1.5"
                            onClick={() => handleOpenInFinder(f)}
                            title="Reveal in Finder"
                          >
                            <FolderOpen className="w-3.5 h-3.5" />
                            Reveal
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            className="gap-1.5"
                            onClick={() => handleBrowseFolder(f)}
                          >
                            Browse
                          </Button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        </ScrollArea>
      </div>

      {/* Preview Panel */}
      <div className={cn(
        "fixed top-0 right-0 w-96 h-full bg-card border-l border-border transform transition-transform duration-300 z-10 shadow-2xl",
        selectedFile ? "translate-x-0" : "translate-x-full"
      )}>
        {selectedFile && (
          <div className="h-full flex flex-col">
            <div className="flex items-center justify-between p-4 border-b border-border">
              <h3 className="font-medium text-sm">File Details</h3>
              <Button variant="ghost" size="icon" className="h-8 w-8 rounded-full" onClick={() => setSelectedFile(null)}>
                <X className="w-4 h-4" />
              </Button>
            </div>
            
            <ScrollArea className="flex-1">
              <div className="p-6 space-y-8">
                <div className="space-y-4">
                  <div className="w-16 h-16 rounded-xl bg-secondary flex items-center justify-center border border-border">
                    <FileIcon className="w-8 h-8 text-muted-foreground" />
                  </div>
                  <div>
                    <h2 className="text-lg font-semibold break-all leading-tight">{selectedFile.name}</h2>
                    <p className="text-sm text-muted-foreground mt-1 uppercase tracking-wider">{selectedFile.extension} File</p>
                  </div>
                </div>

                <Separator />

                <div className="space-y-4 text-sm">
                  <div className="grid grid-cols-3 gap-2">
                    <span className="text-muted-foreground flex items-center gap-2"><HardDrive className="w-3.5 h-3.5" /> Size</span>
                    <span className="col-span-2 font-medium">{formatBytes(selectedFile.size_bytes)}</span>
                  </div>
                  <div className="grid grid-cols-3 gap-2">
                    <span className="text-muted-foreground flex items-center gap-2"><Calendar className="w-3.5 h-3.5" /> Modified</span>
                    <span className="col-span-2 font-medium">{formatDate(selectedFile.modified_at)}</span>
                  </div>
                  <div className="grid grid-cols-3 gap-2">
                    <span className="text-muted-foreground flex items-center gap-2"><Calendar className="w-3.5 h-3.5" /> Created</span>
                    <span className="col-span-2 font-medium">{formatDate(selectedFile.created_at)}</span>
                  </div>
                  <div className="grid grid-cols-3 gap-2">
                    <span className="text-muted-foreground flex items-center gap-2"><Folder className="w-3.5 h-3.5" /> Path</span>
                    <span className="col-span-2 font-mono text-xs break-all bg-secondary p-1.5 rounded">{selectedFile.path}</span>
                  </div>
                </div>

                <Separator />

                <div className="space-y-3">
                  <h4 className="font-medium text-sm flex items-center gap-2">
                    <Search className="w-4 h-4 text-primary" />
                    Similar Files
                  </h4>
                  <Button 
                    variant="outline" 
                    className="w-full justify-start text-muted-foreground h-auto py-3"
                    onClick={() => handleMoreLikeThis(selectedFile.id)}
                    disabled={moreLikeThis.isPending}
                  >
                    <Copy className="w-4 h-4 mr-2" />
                    {moreLikeThis.isPending ? "Finding similar files..." : "Find More Like This"}
                  </Button>
                </div>
              </div>
            </ScrollArea>
            
            <div className="p-4 border-t border-border bg-card/50 backdrop-blur space-y-2">
              <Button 
                className="w-full" 
                onClick={() => handleOpenInFinder(selectedFile.path)}
              >
                <Maximize2 className="w-4 h-4 mr-2" />
                Reveal in Finder
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
