import { ScrollArea } from "@/components/ui/scroll-area";
import { Badge } from "@/components/ui/badge";
import {
  Search,
  Waves,
  FolderOpen,
  Sparkles,
  FileText,
  Copy,
  HardDrive,
  RefreshCw,
  Zap,
  BookOpen,
} from "lucide-react";

interface FeatureCardProps {
  icon: React.ReactNode;
  title: string;
  description: string;
  requiresKey?: boolean;
}

function FeatureCard({ icon, title, description, requiresKey }: FeatureCardProps) {
  return (
    <div className="bg-card border border-border rounded-lg p-5 flex flex-col gap-3">
      <div className="text-primary">{icon}</div>
      <div>
        <div className="flex items-center gap-2 mb-1.5">
          <h3 className="font-semibold text-foreground text-sm">{title}</h3>
          {requiresKey ? (
            <Badge variant="secondary" className="text-[10px] px-1.5 py-0 font-medium tracking-wide uppercase">
              API Key Required
            </Badge>
          ) : (
            <Badge variant="outline" className="text-[10px] px-1.5 py-0 font-medium tracking-wide uppercase text-muted-foreground">
              No API Key Needed
            </Badge>
          )}
        </div>
        <p className="text-sm text-muted-foreground leading-relaxed">{description}</p>
      </div>
    </div>
  );
}

interface TipProps {
  children: React.ReactNode;
}

function Tip({ children }: TipProps) {
  return (
    <li className="flex gap-2 text-sm text-muted-foreground leading-relaxed">
      <span className="text-primary mt-0.5 flex-shrink-0">→</span>
      <span>{children}</span>
    </li>
  );
}

export default function HelpPage() {
  return (
    <div className="flex flex-col h-full overflow-hidden">
      <div className="px-8 py-6 border-b border-border flex-shrink-0">
        <div className="flex items-center gap-3">
          <BookOpen className="w-5 h-5 text-primary" />
          <div>
            <h1 className="text-lg font-semibold text-foreground">Help & Guide</h1>
            <p className="text-sm text-muted-foreground">Everything you need to know about Querymindr.</p>
          </div>
        </div>
      </div>

      <ScrollArea className="flex-1">
        <div className="px-8 py-8 max-w-4xl space-y-10">

          {/* Overview */}
          <section className="space-y-4">
            <p className="text-muted-foreground leading-relaxed">
              Querymindr is a local search tool built for large external drives. It indexes every file
              on your drive into a fast database on your Mac — then lets you search by word, partial
              title, or plain language in seconds, no matter how many terabytes you have.
              Everything runs on your machine. Your files never leave your computer.
            </p>
            <p className="text-muted-foreground leading-relaxed">
              Querymindr works great right out of the box — no account, no subscription, no internet
              connection required. But add a free API key from{" "}
              <span className="text-foreground font-medium">Anthropic</span> and you unlock a whole
              new level of power: describe what you're looking for in plain English and let AI find
              it for you, get instant summaries of large search results, and surface files you didn't
              even know to search for. Go to{" "}
              <strong className="text-foreground">Settings → Anthropic API Key</strong> to add yours.
            </p>
          </section>

          {/* Features */}
          <section>
            <div className="flex items-center gap-2 mb-1">
              <div className="h-px w-6 bg-primary" />
              <span className="text-xs font-semibold uppercase tracking-wider text-primary">Overview</span>
            </div>
            <h2 className="text-xl font-bold text-foreground mb-5">What You Can Do</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <FeatureCard
                icon={<Search className="w-5 h-5" />}
                title="Keyword Search"
                description="Type any word or phrase and instantly find matching files across your entire drive. Results appear in seconds."
              />
              <FeatureCard
                icon={<Waves className="w-5 h-5" />}
                title="Fuzzy / Typo Tolerance"
                description={"Misspell a word and Querymindr still finds it \u2014 \"flowar\" finds \"flower.\" Kicks in automatically when there are no exact matches."}
              />
              <FeatureCard
                icon={<FolderOpen className="w-5 h-5" />}
                title="Folder Search & Browse"
                description='Search by folder name, browse its contents, and Reveal in Finder with one click. Use the "Folder" toggle in the search bar.'
              />
              <FeatureCard
                icon={<Copy className="w-5 h-5" />}
                title="Find More Like This"
                description="Click any file to open its detail panel, then click Find More Like This to surface related files based on naming patterns."
              />
              <FeatureCard
                icon={<Copy className="w-5 h-5" />}
                title="Duplicate Finder"
                description="The Duplicates page scans your index for files with identical content (by checksum) so you can safely remove copies and reclaim space."
              />
              <FeatureCard
                icon={<Sparkles className="w-5 h-5" />}
                title="AI Magic Search"
                description={"Describe what you're looking for in plain language \u2014 \"invoices from last year over $500\" \u2014 and Claude finds the best matches across your entire drive."}
                requiresKey
              />
              <FeatureCard
                icon={<FileText className="w-5 h-5" />}
                title="AI Summary"
                description="After a search, click Generate AI Summary to get a plain-English overview of what was found — useful for large result sets."
                requiresKey
              />
            </div>
          </section>

          {/* Getting Started */}
          <section>
            <div className="flex items-center gap-2 mb-1">
              <div className="h-px w-6 bg-primary" />
              <span className="text-xs font-semibold uppercase tracking-wider text-primary">Setup</span>
            </div>
            <h2 className="text-xl font-bold text-foreground mb-5">Getting Started</h2>
            <div className="space-y-4">

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span className="text-primary font-bold text-xs">1</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <HardDrive className="w-4 h-4 text-primary" />
                    <h3 className="font-semibold text-foreground text-sm">Add Your Drive</h3>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed pl-10">
                  Go to <strong className="text-foreground">Settings → Index Engine</strong> and confirm your drive path (e.g. <code className="bg-secondary px-1.5 py-0.5 rounded text-xs">/Volumes/Thunderbay</code>) is listed. Add more folders if needed.
                </p>
              </div>

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span className="text-primary font-bold text-xs">2</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <RefreshCw className="w-4 h-4 text-primary" />
                    <h3 className="font-semibold text-foreground text-sm">Build the Index</h3>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed pl-10">
                  Click <strong className="text-foreground">Full Re-index</strong> the first time — this scans every file and takes a few hours for large drives.
                  After that, run <strong className="text-foreground">Quick Update</strong> regularly (minutes only) to catch new and changed files.
                </p>
              </div>

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span className="text-primary font-bold text-xs">3</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <Search className="w-4 h-4 text-primary" />
                    <h3 className="font-semibold text-foreground text-sm">Search</h3>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed pl-10">
                  Type anything into the search bar. Results appear instantly. Click any result to
                  open the detail panel where you can Reveal in Finder, copy the path, or find similar files.
                </p>
              </div>

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span className="text-primary font-bold text-xs">4</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-4 h-4 text-primary" />
                    <h3 className="font-semibold text-foreground text-sm">Enable AI (Optional)</h3>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed pl-10">
                  Go to <strong className="text-foreground">Settings → Anthropic API Key</strong> and paste your key from{" "}
                  <span className="text-foreground font-medium">console.anthropic.com</span>.
                  The AI Magic and Summary features will appear in the search bar automatically.
                </p>
              </div>

            </div>
          </section>

          {/* Tips */}
          <section>
            <div className="flex items-center gap-2 mb-1">
              <div className="h-px w-6 bg-primary" />
              <span className="text-xs font-semibold uppercase tracking-wider text-primary">Tips</span>
            </div>
            <h2 className="text-xl font-bold text-foreground mb-5">Search Tips</h2>
            <div className="bg-card border border-border rounded-lg p-6">
              <ul className="space-y-3">
                <Tip>Search is word-based — <strong className="text-foreground">"budget proposal"</strong> finds files containing both words anywhere in their name.</Tip>
                <Tip>Partial words work — <strong className="text-foreground">"report"</strong> matches "reports", "reporting", "quarterly-report-2024".</Tip>
                <Tip>Use the <strong className="text-foreground">Folder toggle</strong> in the search bar to switch between searching files and searching folder names.</Tip>
                <Tip>Fuzzy matching kicks in automatically when your search returns zero results (works best with 5+ character queries).</Tip>
                <Tip><strong className="text-foreground">Quick Update</strong> is all you need after adding new files — save Full Re-index for major drive reorganizations.</Tip>
                <Tip>Use <strong className="text-foreground">Reveal in Finder</strong> in the detail panel to jump directly to any file or folder on disk.</Tip>
                <Tip>The <strong className="text-foreground">Duplicates</strong> page uses file checksums — two files must have identical content to be flagged, not just identical names.</Tip>
              </ul>
            </div>
          </section>

          {/* Open in Browser */}
          <section>
            <div className="flex items-center gap-2 mb-1">
              <div className="h-px w-6 bg-primary" />
              <span className="text-xs font-semibold uppercase tracking-wider text-primary">Access</span>
            </div>
            <h2 className="text-xl font-bold text-foreground mb-5">Opening Querymindr</h2>
            <div className="bg-card border border-border rounded-lg p-5">
              <div className="flex items-start gap-3">
                <Zap className="w-4 h-4 text-primary mt-0.5 flex-shrink-0" />
                <div className="space-y-2 text-sm text-muted-foreground leading-relaxed">
                  <p>
                    Querymindr runs automatically in the background whenever your Mac is on.
                    To open it, type this address into any browser:
                  </p>
                  <code className="block bg-secondary text-foreground px-4 py-2.5 rounded text-sm font-mono">
                    http://localhost:8080/querymindr/
                  </code>
                  <p>Bookmark it for one-click access.</p>
                </div>
              </div>
            </div>
          </section>

          <div className="pb-4" />
        </div>
      </ScrollArea>
    </div>
  );
}
