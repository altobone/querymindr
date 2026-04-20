import { ScrollArea } from "@/components/ui/scroll-area";
import {
  Search,
  Waves,
  FolderOpen,
  Copy,
  HardDrive,
  RefreshCw,
  BookOpen,
  Fingerprint,
  TriangleAlert,
  Trash2,
} from "lucide-react";

interface FeatureCardProps {
  icon: React.ReactNode;
  title: string;
  description: string;
}

function FeatureCard({ icon, title, description }: FeatureCardProps) {
  return (
    <div className="bg-card border border-border rounded-lg p-5 flex flex-col gap-3">
      <div style={{ color: "#e8ff47" }}>{icon}</div>
      <div>
        <h3 className="font-semibold text-foreground text-sm mb-1.5">{title}</h3>
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
          <BookOpen className="w-5 h-5" style={{ color: "#e8ff47" }} />
          <div>
            <h1 className="text-lg font-semibold" style={{ color: "#e8ff47" }}>Help & Guide</h1>
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
              on your drive into a fast local database — then lets you search by word, partial name,
              or phrase in seconds, no matter how many terabytes you have.
              It also scans for duplicate files so you can reclaim wasted space without guessing.
              Everything runs on your machine. Your files never leave your computer.
            </p>
            <p className="text-muted-foreground leading-relaxed">
              No account, no subscription, no internet connection required. Just index and search.
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
                icon={<Fingerprint className="w-5 h-5" />}
                title="Duplicate Finder"
                description="The Duplicates page scans your index for files with identical content (by checksum) so you can safely identify and remove copies to reclaim space."
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
                    <HardDrive className="w-4 h-4" style={{ color: "#e8ff47" }} />
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
                    <RefreshCw className="w-4 h-4" style={{ color: "#e8ff47" }} />
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
                    <Search className="w-4 h-4" style={{ color: "#e8ff47" }} />
                    <h3 className="font-semibold text-foreground text-sm">Search</h3>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed pl-10">
                  Type anything into the search bar. Results appear instantly. Click any result to
                  open the detail panel where you can Reveal in Finder, copy the path, or find similar files.
                </p>
              </div>

            </div>
          </section>

          {/* Duplicates */}
          <section>
            <div className="flex items-center gap-2 mb-1">
              <div className="h-px w-6 bg-primary" />
              <span className="text-xs font-semibold uppercase tracking-wider text-primary">Duplicates</span>
            </div>
            <h2 className="text-xl font-bold text-foreground mb-2">Using the Duplicate Finder</h2>
            <p className="text-sm text-muted-foreground leading-relaxed mb-5">
              Large drives accumulate duplicate files over time — backups copied twice, folders
              synced in multiple places, downloads saved more than once. The Duplicates page finds
              them all and shows you exactly how much space you can get back.
            </p>
            <div className="space-y-4">

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-start gap-3">
                  <Fingerprint className="w-4 h-4 mt-0.5 flex-shrink-0" style={{ color: "#e8ff47" }} />
                  <div>
                    <h3 className="font-semibold text-foreground text-sm mb-1.5">How it works</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">
                      Querymindr computes a unique <strong className="text-foreground">checksum</strong> (a digital fingerprint)
                      for every file during indexing. Two files are only flagged as duplicates if their
                      content is byte-for-byte identical — not just if they have the same name or size.
                      This means you'll never get false positives.
                    </p>
                  </div>
                </div>
              </div>

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-start gap-3">
                  <Search className="w-4 h-4 mt-0.5 flex-shrink-0" style={{ color: "#e8ff47" }} />
                  <div>
                    <h3 className="font-semibold text-foreground text-sm mb-1.5">Running a scan</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">
                      Click <strong className="text-foreground">Duplicates</strong> in the sidebar. Results are
                      grouped by content — each group shows all the copies of the same file, their
                      locations, sizes, and the total space consumed by the duplicates. You can filter
                      by file type or minimum file size to focus on what matters most.
                    </p>
                  </div>
                </div>
              </div>

              <div className="bg-card border border-border rounded-lg p-5">
                <div className="flex items-start gap-3">
                  <Trash2 className="w-4 h-4 mt-0.5 flex-shrink-0" style={{ color: "#e8ff47" }} />
                  <div>
                    <h3 className="font-semibold text-foreground text-sm mb-1.5">Removing duplicates</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">
                      Click <strong className="text-foreground">Reveal in Finder</strong> on any file to locate it
                      on disk before deleting. Always keep at least one copy from each group —
                      Querymindr shows you all the locations so you can decide which one to keep
                      and which to remove.
                    </p>
                  </div>
                </div>
              </div>

              <div className="bg-card border border-border rounded-lg p-5 border-amber-500/20">
                <div className="flex items-start gap-3">
                  <TriangleAlert className="w-4 h-4 text-amber-500 mt-0.5 flex-shrink-0" />
                  <div>
                    <h3 className="font-semibold text-foreground text-sm mb-1.5">Before you delete</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed">
                      Querymindr shows you the duplicates — it does not delete anything automatically.
                      Always review each group in Finder before removing files, and make sure you have
                      a backup of anything important. Checksums confirm identical content, but you
                      still decide what stays.
                    </p>
                  </div>
                </div>
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
                <Zap className="w-4 h-4 mt-0.5 flex-shrink-0" style={{ color: "#e8ff47" }} />
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
