import { useState, useEffect } from "react";
import { useGetIndexStatus, useStartIndexing, useGetIndexStats } from "@workspace/api-client-react";
import { formatBytes, formatDate } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { HardDrive, Activity, AlertTriangle, CheckCircle2, Cpu, KeyRound, Eye, EyeOff } from "lucide-react";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useToast } from "@/hooks/use-toast";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";

const MODEL_STORAGE_KEY = "file-finder-ai-model";
const MODEL_OPTIONS = [
  { value: "claude-haiku-4-5", label: "Claude Haiku", description: "Faster & cheaper — great for most searches" },
  { value: "claude-sonnet-4-5", label: "Claude Sonnet", description: "More powerful — better for complex queries" },
];

export default function SettingsPage() {
  const [rootDir, setRootDir] = useState("/Users/admin");
  const [selectedModel, setSelectedModel] = useState<string>(() => localStorage.getItem(MODEL_STORAGE_KEY) ?? "claude-haiku-4-5");
  const [apiKey, setApiKey] = useState("");
  const [showKey, setShowKey] = useState(false);
  const [apiKeyStatus, setApiKeyStatus] = useState<"unknown" | "configured" | "missing">("unknown");
  const [savingKey, setSavingKey] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    fetch("/file-finder/ai-config")
      .then((r) => r.json())
      .then((d: { configured: boolean }) => setApiKeyStatus(d.configured ? "configured" : "missing"))
      .catch(() => setApiKeyStatus("missing"));
  }, []);

  const handleSaveApiKey = async () => {
    setSavingKey(true);
    try {
      const res = await fetch("/file-finder/ai-config", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ api_key: apiKey }),
      });
      const data = await res.json() as { ok: boolean; message: string };
      if (data.ok) {
        setApiKeyStatus(apiKey.trim() ? "configured" : "missing");
        setApiKey("");
        toast({ title: apiKey.trim() ? "API Key Saved" : "API Key Cleared", description: data.message });
      }
    } catch {
      toast({ title: "Error", description: "Could not save API key.", variant: "destructive" });
    } finally {
      setSavingKey(false);
    }
  };

  const handleModelChange = (value: string) => {
    setSelectedModel(value);
    localStorage.setItem(MODEL_STORAGE_KEY, value);
    toast({ title: "AI Model Updated", description: `Now using ${MODEL_OPTIONS.find(m => m.value === value)?.label ?? value} for searches.` });
  };

  const { data: statusData, refetch: refetchStatus } = useGetIndexStatus();
  const { data: statsData, refetch: refetchStats } = useGetIndexStats();
  const startIndex = useStartIndexing();

  const isRunning = statusData?.status === "running";

  useEffect(() => {
    let interval: NodeJS.Timeout;
    if (isRunning) {
      interval = setInterval(() => {
        refetchStatus();
      }, 2000);
    } else {
      refetchStats();
    }
    return () => clearInterval(interval);
  }, [isRunning, refetchStatus, refetchStats]);

  const handleStart = () => {
    if (!rootDir) return;
    startIndex.mutate({ data: { root_dir: rootDir } }, {
      onSuccess: () => {
        toast({ title: "Indexing Started", description: `Scanning ${rootDir}...` });
        refetchStatus();
      }
    });
  };

  const progress = statusData && statusData.total_files > 0 
    ? (statusData.processed_files / statusData.total_files) * 100 
    : 0;

  return (
    <div className="flex flex-col h-full bg-background">
      <div className="border-b border-border bg-card p-6 shrink-0">
        <h2 className="text-xl font-semibold tracking-tight">Settings & Indexing</h2>
        <p className="text-sm text-muted-foreground mt-1">Configure root directories and monitor background indexing.</p>
      </div>

      <ScrollArea className="flex-1 p-6">
        <div className="max-w-3xl space-y-8 pb-10">

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <KeyRound className="w-5 h-5 text-primary" />
                Anthropic API Key
                {apiKeyStatus === "configured" && <Badge variant="secondary" className="ml-2 text-green-400 border-green-600">Configured</Badge>}
                {apiKeyStatus === "missing" && <Badge variant="secondary" className="ml-2 text-yellow-400 border-yellow-600">Not set</Badge>}
              </CardTitle>
              <CardDescription>
                Required for AI Search, file summaries, and "More Like This". Get your key at <span className="font-mono text-xs">console.anthropic.com</span>. Saved for this server session only.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex gap-2 items-center">
                <div className="relative flex-1">
                  <Input
                    type={showKey ? "text" : "password"}
                    value={apiKey}
                    onChange={(e) => setApiKey(e.target.value)}
                    placeholder={apiKeyStatus === "configured" ? "Key is set — paste a new one to replace" : "sk-ant-…"}
                    className="font-mono text-sm pr-10"
                    onKeyDown={(e) => e.key === "Enter" && handleSaveApiKey()}
                  />
                  <button
                    type="button"
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    onClick={() => setShowKey((v) => !v)}
                  >
                    {showKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
                <Button onClick={handleSaveApiKey} disabled={savingKey || !apiKey.trim()} className="w-24">
                  {savingKey ? "Saving…" : "Save Key"}
                </Button>
                {apiKeyStatus === "configured" && (
                  <Button variant="ghost" onClick={async () => {
                    setSavingKey(true);
                    try {
                      await fetch("/file-finder/ai-config", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ api_key: "" }) });
                      setApiKeyStatus("missing");
                      setApiKey("");
                      toast({ title: "API Key Cleared" });
                    } finally { setSavingKey(false); }
                  }} disabled={savingKey} className="text-destructive hover:text-destructive">
                    Clear
                  </Button>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Cpu className="w-5 h-5 text-primary" />
                AI Model
              </CardTitle>
              <CardDescription>
                Choose which Claude model powers AI search, summaries, and similar-file detection.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <Select value={selectedModel} onValueChange={handleModelChange}>
                  <SelectTrigger className="w-64">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {MODEL_OPTIONS.map((m) => (
                      <SelectItem key={m.value} value={m.value}>
                        {m.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  {MODEL_OPTIONS.find(m => m.value === selectedModel)?.description}
                </p>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Activity className="w-5 h-5 text-primary" />
                Index Engine
              </CardTitle>
              <CardDescription>
                Point the engine to your root drive to build the search database.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex gap-3 items-end">
                <div className="flex-1 space-y-2">
                  <label className="text-sm font-medium">Root Directory to Scan</label>
                  <Input 
                    value={rootDir}
                    onChange={(e) => setRootDir(e.target.value)}
                    placeholder="/Users/username"
                    disabled={isRunning}
                    className="font-mono text-sm"
                  />
                </div>
                <Button 
                  onClick={handleStart} 
                  disabled={isRunning || !rootDir}
                  className="w-32"
                >
                  {isRunning ? "Running..." : "Start Indexing"}
                </Button>
              </div>

              {statusData && (
                <div className="bg-secondary/50 rounded-lg p-5 border border-border">
                  <div className="flex justify-between items-end mb-4">
                    <div>
                      <h4 className="font-semibold text-sm flex items-center gap-2">
                        {statusData.status === "running" && <Activity className="w-4 h-4 text-primary animate-pulse" />}
                        {statusData.status === "completed" && <CheckCircle2 className="w-4 h-4 text-green-500" />}
                        {statusData.status === "failed" && <AlertTriangle className="w-4 h-4 text-destructive" />}
                        {statusData.status === "idle" && <HardDrive className="w-4 h-4 text-muted-foreground" />}
                        Status: <span className="capitalize">{statusData.status}</span>
                      </h4>
                      {statusData.error_message && (
                        <p className="text-xs text-destructive mt-1">{statusData.error_message}</p>
                      )}
                    </div>
                    {isRunning && (
                      <div className="text-right">
                        <span className="text-2xl font-bold font-mono">{Math.round(progress)}%</span>
                        <p className="text-xs text-muted-foreground">
                          {statusData.processed_files.toLocaleString()} / {statusData.total_files.toLocaleString()} files
                        </p>
                      </div>
                    )}
                  </div>
                  {isRunning && (
                    <Progress value={progress} className="h-2 w-full bg-secondary" />
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {statsData && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-lg">
                  <HardDrive className="w-5 h-5 text-primary" />
                  Database Statistics
                </CardTitle>
                <CardDescription>
                  Overview of the current file index.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 gap-4 mb-8">
                  <div className="bg-card border rounded-lg p-4 shadow-sm">
                    <span className="text-sm font-medium text-muted-foreground block mb-1">Total Files Indexed</span>
                    <span className="text-3xl font-bold tracking-tight">{statsData.total_files.toLocaleString()}</span>
                  </div>
                  <div className="bg-card border rounded-lg p-4 shadow-sm">
                    <span className="text-sm font-medium text-muted-foreground block mb-1">Total Space Tracked</span>
                    <span className="text-3xl font-bold tracking-tight text-primary">{formatBytes(statsData.total_size_bytes)}</span>
                  </div>
                </div>

                <div className="space-y-4">
                  <h4 className="font-medium text-sm border-b pb-2">File Types Breakdown</h4>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {statsData.file_types.map((ft) => (
                      <div key={ft.extension} className="flex items-center justify-between p-3 bg-secondary/30 rounded-md border border-border/50 hover:bg-secondary/50 transition-colors">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded bg-background border flex items-center justify-center">
                            <span className="text-[10px] font-bold uppercase">{ft.extension.substring(0,3)}</span>
                          </div>
                          <div>
                            <p className="text-sm font-medium">{ft.extension}</p>
                            <p className="text-xs text-muted-foreground">{ft.count.toLocaleString()} files</p>
                          </div>
                        </div>
                        <span className="text-sm font-mono">{formatBytes(ft.total_size_bytes)}</span>
                      </div>
                    ))}
                  </div>
                </div>
                
                {statsData.last_indexed && (
                  <p className="text-xs text-muted-foreground mt-8 text-center">
                    Last updated: {formatDate(statsData.last_indexed)}
                  </p>
                )}
              </CardContent>
            </Card>
          )}
          
        </div>
      </ScrollArea>
    </div>
  );
}
