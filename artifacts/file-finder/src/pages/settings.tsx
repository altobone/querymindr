import { useState, useEffect } from "react";
import { useGetIndexStatus, useStartIndexing, useGetIndexStats } from "@workspace/api-client-react";
import { formatBytes, formatDate } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { HardDrive, Activity, AlertTriangle, CheckCircle2, Cpu, KeyRound, Eye, EyeOff, Zap, RefreshCw, Clock, Database, Plus, X, ShieldCheck, ShoppingCart, Loader2 } from "lucide-react";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useToast } from "@/hooks/use-toast";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";

const MODEL_STORAGE_KEY = "querymindr-ai-model";
const MODEL_OPTIONS = [
  { value: "claude-haiku-4-5", label: "Claude Haiku", description: "Faster & cheaper — great for most searches" },
  { value: "claude-sonnet-4-5", label: "Claude Sonnet", description: "More powerful — better for complex queries" },
];

const SCHEDULE_OPTIONS = [
  { value: "0",  label: "Disabled",      description: "Never auto-update — run manually only" },
  { value: "1",  label: "Every hour",    description: "Keeps the index very fresh; fine for fast drives" },
  { value: "6",  label: "Every 6 hours", description: "Four times a day" },
  { value: "12", label: "Every 12 hours",description: "Twice a day — midnight & noon (default)" },
  { value: "24", label: "Every 24 hours",description: "Once a day at midnight" },
];

const CHECKSUM_OPTIONS = [
  { value: "50",   label: "50 MB",  description: "Fastest — skips checksums on larger files" },
  { value: "100",  label: "100 MB", description: "Default — covers most audio tracks" },
  { value: "250",  label: "250 MB", description: "Covers large stems and rendered mixes" },
  { value: "500",  label: "500 MB", description: "Covers most video exports" },
  { value: "1000", label: "1 GB",   description: "Thorough — slower on very large files" },
];

interface LicenseStatus {
  licensed: boolean;
  daysRemaining: number;
  installDate: string;
}

export default function SettingsPage() {
  const [rootDirs, setRootDirs] = useState<string[]>([]);
  const [newDirInput, setNewDirInput] = useState("");
  const [selectedModel, setSelectedModel] = useState<string>(() => localStorage.getItem(MODEL_STORAGE_KEY) ?? "claude-haiku-4-5");
  const [apiKey, setApiKey] = useState("");
  const [showKey, setShowKey] = useState(false);
  const [apiKeyStatus, setApiKeyStatus] = useState<"unknown" | "configured" | "missing">("unknown");
  const [savingKey, setSavingKey] = useState(false);
  const [scheduleInterval, setScheduleInterval] = useState("12");
  const [checksumLimit, setChecksumLimit] = useState("100");
  const { toast } = useToast();

  const [licenseStatus, setLicenseStatus] = useState<LicenseStatus | null>(null);
  const [licenseKey, setLicenseKey] = useState("");
  const [activatingKey, setActivatingKey] = useState(false);
  const [showLicenseKey, setShowLicenseKey] = useState(false);

  useEffect(() => {
    fetch("/api/querymindr/ai-config")
      .then((r) => r.json())
      .then((d: { configured: boolean }) => setApiKeyStatus(d.configured ? "configured" : "missing"))
      .catch(() => setApiKeyStatus("missing"));

    fetch("/api/querymindr/app-config")
      .then((r) => r.json())
      .then((d: { auto_index_interval_hours: number; checksum_limit_mb: number; root_dirs: string[] }) => {
        setScheduleInterval(String(d.auto_index_interval_hours ?? 12));
        setChecksumLimit(String(d.checksum_limit_mb ?? 100));
        if (d.root_dirs?.length) setRootDirs(d.root_dirs);
      })
      .catch(() => {});

    fetch("/api/querymindr/license/status")
      .then((r) => r.json())
      .then((d) => setLicenseStatus(d as LicenseStatus))
      .catch(() => {});
  }, []);

  const handleActivateLicense = async () => {
    if (!licenseKey.trim()) return;
    setActivatingKey(true);
    try {
      const res = await fetch("/api/querymindr/license/activate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ key: licenseKey.trim() }),
      });
      const data = await res.json() as { ok: boolean; message: string };
      if (data.ok) {
        toast({ title: "License Activated", description: data.message });
        setLicenseKey("");
        // Re-fetch status
        fetch("/api/querymindr/license/status")
          .then(r => r.json())
          .then(d => setLicenseStatus(d as LicenseStatus))
          .catch(() => {});
      } else {
        toast({ title: "Invalid Key", description: data.message, variant: "destructive" });
      }
    } catch {
      toast({ title: "Error", description: "Could not connect to server.", variant: "destructive" });
    } finally {
      setActivatingKey(false);
    }
  };

  const saveAppConfig = async (patch: { auto_index_interval_hours?: number; checksum_limit_mb?: number; root_dirs?: string[] }) => {
    try {
      await fetch("/api/querymindr/app-config", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(patch),
      });
    } catch {
      toast({ title: "Error", description: "Could not save setting.", variant: "destructive" });
    }
  };

  const handleAddDrive = () => {
    const trimmed = newDirInput.trim();
    if (!trimmed || rootDirs.includes(trimmed)) return;
    const updated = [...rootDirs, trimmed];
    setRootDirs(updated);
    setNewDirInput("");
    saveAppConfig({ root_dirs: updated });
    toast({ title: "Drive Added", description: trimmed });
  };

  const handleRemoveDrive = (dir: string) => {
    const updated = rootDirs.filter(d => d !== dir);
    setRootDirs(updated);
    saveAppConfig({ root_dirs: updated });
    toast({ title: "Drive Removed", description: dir });
  };

  const handleScheduleChange = (value: string) => {
    setScheduleInterval(value);
    saveAppConfig({ auto_index_interval_hours: Number(value) });
    const label = SCHEDULE_OPTIONS.find(o => o.value === value)?.label ?? value;
    toast({ title: "Schedule Updated", description: label === "Disabled" ? "Auto-update is now off." : `Auto-update set to: ${label}` });
  };

  const handleChecksumChange = (value: string) => {
    setChecksumLimit(value);
    saveAppConfig({ checksum_limit_mb: Number(value) });
    toast({ title: "Checksum Limit Updated", description: `Files up to ${CHECKSUM_OPTIONS.find(o => o.value === value)?.label} will be fingerprinted for duplicate detection.` });
  };

  const handleSaveApiKey = async () => {
    setSavingKey(true);
    try {
      const res = await fetch("/api/querymindr/ai-config", {
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
  const [incrementalRunning, setIncrementalRunning] = useState(false);

  const isRunning = statusData?.status === "running";

  useEffect(() => {
    let interval: NodeJS.Timeout;
    if (isRunning) {
      interval = setInterval(() => {
        refetchStatus();
      }, 2000);
    } else {
      refetchStats();
      setIncrementalRunning(false);
    }
    return () => clearInterval(interval);
  }, [isRunning, refetchStatus, refetchStats]);

  const handleStart = () => {
    if (rootDirs.length === 0) return;
    startIndex.mutate({ data: {} }, {
      onSuccess: () => {
        const label = rootDirs.length === 1 ? rootDirs[0] : `${rootDirs.length} drives`;
        toast({ title: "Full Re-index Started", description: `Scanning all files in ${label}...` });
        refetchStatus();
      }
    });
  };

  const handleIncremental = async () => {
    if (rootDirs.length === 0 || isRunning) return;
    setIncrementalRunning(true);
    try {
      await fetch("/api/querymindr/index/incremental", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const label = rootDirs.length === 1 ? rootDirs[0] : `${rootDirs.length} drives`;
      toast({ title: "Quick Update Started", description: `Scanning only changed folders in ${label}...` });
      refetchStatus();
    } catch {
      toast({ title: "Error", description: "Could not start quick update.", variant: "destructive" });
      setIncrementalRunning(false);
    }
  };

  const progress = statusData && statusData.total_files > 0 
    ? (statusData.processed_files / statusData.total_files) * 100 
    : 0;

  return (
    <div className="flex flex-col h-full bg-background">
      <div className="border-b border-border bg-card p-6 shrink-0">
        <h2 className="text-xl font-semibold tracking-tight" style={{ color: "#e8ff47" }}>Settings & Indexing</h2>
        <p className="text-sm text-muted-foreground mt-1">Configure root directories and monitor background indexing.</p>
      </div>

      <ScrollArea className="flex-1 p-6">
        <div className="max-w-3xl space-y-8 pb-10">

          {/* License status card */}
          {licenseStatus && (
            <Card className={licenseStatus.licensed ? "border-green-700/40" : licenseStatus.daysRemaining <= 2 ? "border-amber-700/40" : ""}>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-lg">
                  {licenseStatus.licensed
                    ? <ShieldCheck className="w-5 h-5 text-green-400" />
                    : <KeyRound className="w-5 h-5" style={{ color: "#e8ff47" }} />}
                  {licenseStatus.licensed ? "Licensed" : "Trial Version"}
                </CardTitle>
                <CardDescription>
                  {licenseStatus.licensed
                    ? "Querymindr is fully licensed. Thank you!"
                    : licenseStatus.daysRemaining === 0
                    ? "Your trial has ended. Enter your license key below to continue."
                    : licenseStatus.daysRemaining === 1
                    ? "1 day remaining in your free trial."
                    : `${licenseStatus.daysRemaining} days remaining in your free trial.`}
                </CardDescription>
              </CardHeader>
              {!licenseStatus.licensed && (
                <CardContent className="space-y-3">
                  <div className="flex gap-2">
                    <div className="relative flex-1">
                      <Input
                        placeholder="QMDR-XXXX-XXXX-XXXX-XXXX"
                        value={showLicenseKey ? licenseKey : licenseKey ? licenseKey.replace(/[^-]/g, "•") : ""}
                        onChange={e => setLicenseKey(e.target.value.replace(/•/g, "").toUpperCase())}
                        onFocus={() => setShowLicenseKey(true)}
                        onBlur={() => setShowLicenseKey(false)}
                        onKeyDown={e => e.key === "Enter" && handleActivateLicense()}
                        className="font-mono tracking-widest pr-10 placeholder:tracking-normal placeholder:font-sans"
                        disabled={activatingKey}
                      />
                      <button
                        type="button"
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                        onClick={() => setShowLicenseKey(v => !v)}
                      >
                        {showLicenseKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                      </button>
                    </div>
                    <Button
                      onClick={handleActivateLicense}
                      disabled={!licenseKey.trim() || activatingKey}
                    >
                      {activatingKey
                        ? <><Loader2 className="w-4 h-4 mr-1.5 animate-spin" />Activating…</>
                        : <><KeyRound className="w-4 h-4 mr-1.5" />Activate</>}
                    </Button>
                  </div>
                  <div className="flex items-center justify-between pt-1">
                    <p className="text-xs text-muted-foreground">
                      Don't have a key?{" "}
                      <a
                        href="https://musicsavvy.com/querymindr"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-indigo-400 hover:underline"
                      >
                        Buy Querymindr — $29
                      </a>
                    </p>
                    <ShoppingCart className="w-3.5 h-3.5 text-muted-foreground" />
                  </div>
                </CardContent>
              )}
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <KeyRound className="w-5 h-5" style={{ color: "#e8ff47" }} />
                Anthropic API Key
                {apiKeyStatus === "configured" && <Badge variant="secondary" className="ml-2 text-green-400 border-green-600">Configured</Badge>}
                {apiKeyStatus === "missing" && <Badge variant="secondary" className="ml-2 text-yellow-400 border-yellow-600">Not set</Badge>}
              </CardTitle>
              <CardDescription>
                Required for AI Search, file summaries, and "More Like This". Get your key at <span className="font-mono text-xs">console.anthropic.com</span>. Saved to your Mac and loaded automatically on every restart.
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
                      await fetch("/api/querymindr/ai-config", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ api_key: "" }) });
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
                <Cpu className="w-5 h-5" style={{ color: "#e8ff47" }} />
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
                <Clock className="w-5 h-5" style={{ color: "#e8ff47" }} />
                Auto-Update Schedule
              </CardTitle>
              <CardDescription>
                How often the index runs a Quick Update automatically in the background.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <Select value={scheduleInterval} onValueChange={handleScheduleChange}>
                <SelectTrigger className="w-64">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SCHEDULE_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">
                {SCHEDULE_OPTIONS.find(o => o.value === scheduleInterval)?.description}
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Database className="w-5 h-5" style={{ color: "#e8ff47" }} />
                Checksum File Size Limit
              </CardTitle>
              <CardDescription>
                Files below this size get a fingerprint used for duplicate detection. Larger files are skipped to save time.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <Select value={checksumLimit} onValueChange={handleChecksumChange}>
                <SelectTrigger className="w-64">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CHECKSUM_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">
                {CHECKSUM_OPTIONS.find(o => o.value === checksumLimit)?.description}
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Activity className="w-5 h-5" style={{ color: "#e8ff47" }} />
                Index Engine
              </CardTitle>
              <CardDescription>
                Add one or more drives or folders to build the search database.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex gap-3 items-start">
                <div className="flex-1 space-y-2">
                  <label className="text-sm font-medium">Drives to Scan</label>

                  {rootDirs.length > 0 && (
                    <div className="space-y-2">
                      {rootDirs.map((dir) => (
                        <div key={dir} className="flex items-center gap-2 bg-secondary/50 rounded-md px-3 py-2 border border-border">
                          <HardDrive className="w-4 h-4 text-muted-foreground shrink-0" />
                          <span className="font-mono text-sm flex-1 truncate">{dir}</span>
                          <button
                            onClick={() => handleRemoveDrive(dir)}
                            disabled={isRunning}
                            className="text-muted-foreground hover:text-destructive transition-colors disabled:opacity-50"
                            title="Remove drive"
                          >
                            <X className="w-4 h-4" />
                          </button>
                        </div>
                      ))}
                    </div>
                  )}

                  <div className="flex gap-2">
                    <Input
                      value={newDirInput}
                      onChange={(e) => setNewDirInput(e.target.value)}
                      onKeyDown={(e) => e.key === "Enter" && handleAddDrive()}
                      placeholder="/Volumes/MyDrive"
                      disabled={isRunning}
                      className="font-mono text-sm"
                    />
                    <Button
                      onClick={handleAddDrive}
                      disabled={isRunning || !newDirInput.trim()}
                      variant="outline"
                      size="icon"
                      title="Add drive"
                    >
                      <Plus className="w-4 h-4" />
                    </Button>
                  </div>
                </div>

                <div className="flex flex-col gap-2 pt-7">
                  <Button
                    onClick={handleIncremental}
                    disabled={isRunning || incrementalRunning || rootDirs.length === 0}
                    variant="outline"
                    className="gap-2"
                  >
                    <Zap className="w-4 h-4" />
                    {isRunning && (statusData as { type?: string })?.type === "incremental" ? "Updating..." : "Quick Update"}
                  </Button>
                  <Button
                    onClick={handleStart}
                    disabled={isRunning || rootDirs.length === 0}
                    variant="secondary"
                    className="gap-2"
                  >
                    <RefreshCw className="w-4 h-4" />
                    {isRunning && (statusData as { type?: string })?.type === "full" ? "Indexing..." : "Full Re-index"}
                  </Button>
                </div>
              </div>

              <div className="text-sm text-muted-foreground space-y-1">
                <p><span className="font-medium text-foreground">Quick Update</span> — scans only folders that changed since the last run. Completes in minutes. Use this regularly.</p>
                <p><span className="font-medium text-foreground">Full Re-index</span> — scans all {statsData?.total_files ? statsData.total_files.toLocaleString() + " files" : "files"} from scratch. Takes several hours. Use only after a major reorganization.</p>
              </div>

              {statusData && (
                <div className="bg-secondary/50 rounded-lg p-5 border border-border">
                  <div className="flex justify-between items-end mb-4">
                    <div>
                      <h4 className="font-semibold text-sm flex items-center gap-2">
                        {statusData.status === "running" && <Activity className="w-4 h-4 text-primary animate-pulse" />}
                        {statusData.status === "completed" && <CheckCircle2 className="w-4 h-4 text-green-500" />}
                        {statusData.status === "failed" && <AlertTriangle className="w-4 h-4 text-destructive" />}
                        {statusData.status === "interrupted" && <AlertTriangle className="w-4 h-4 text-amber-500" />}
                        {statusData.status === "idle" && <HardDrive className="w-4 h-4 text-muted-foreground" />}
                        {(statusData as { type?: string })?.type === "incremental" ? "Quick Update" : "Full Index"}
                        {" · "}
                        <span className="capitalize font-normal text-muted-foreground">{statusData.status}</span>
                      </h4>
                      {statusData.error_message && (
                        <p className="text-xs text-destructive mt-1">{statusData.error_message}</p>
                      )}
                    </div>
                    {isRunning && (
                      <div className="text-right">
                        {statusData.total_files > 0 ? (
                          <>
                            <span className="text-2xl font-bold font-mono">{Math.round(progress)}%</span>
                            <p className="text-xs text-muted-foreground">
                              {statusData.processed_files.toLocaleString()} / {statusData.total_files.toLocaleString()} files
                            </p>
                          </>
                        ) : statusData.processed_files > 0 ? (
                          <>
                            <span className="text-2xl font-bold font-mono animate-pulse">…</span>
                            <p className="text-xs text-muted-foreground">
                              {statusData.processed_files.toLocaleString()} files indexed
                            </p>
                          </>
                        ) : (
                          <p className="text-xs text-muted-foreground animate-pulse">Scanning…</p>
                        )}
                      </div>
                    )}
                  </div>
                  {isRunning && statusData.total_files > 0 && (
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
