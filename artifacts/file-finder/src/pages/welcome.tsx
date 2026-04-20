import { useLocation } from "wouter";
import { Button } from "@/components/ui/button";
import { Database, Search, ArrowRight, X } from "lucide-react";
import logo from "@assets/Queryminder-logo_white_1776223766599.png";

const WELCOME_KEY = "querymindr_welcomed";

export function hasSeenWelcome(): boolean {
  return localStorage.getItem(WELCOME_KEY) === "true";
}

export function markWelcomeSeen() {
  localStorage.setItem(WELCOME_KEY, "true");
}

interface WelcomeScreenProps {
  onDismiss: () => void;
}

export default function WelcomeScreen({ onDismiss }: WelcomeScreenProps) {
  const [, navigate] = useLocation();

  function handleOpenSettings() {
    markWelcomeSeen();
    onDismiss();
    navigate("/settings");
  }

  function handleStartExploring() {
    markWelcomeSeen();
    onDismiss();
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-background/90 backdrop-blur-sm">
      <div className="relative w-full max-w-lg mx-4 bg-card border border-border rounded-xl shadow-2xl overflow-hidden">

        {/* Header */}
        <div className="px-8 pt-8 pb-6 border-b border-border/50 text-center">
          <img src={logo} alt="Querymindr" className="h-7 w-auto mx-auto mb-5" />
          <h1 className="text-2xl font-bold tracking-tight text-foreground mb-2">
            Welcome to Querymindr
          </h1>
          <p className="text-sm text-muted-foreground leading-relaxed">
            Your personal search engine for large external drives. Everything runs on your Mac — your files never leave your computer.
          </p>
        </div>

        {/* Steps */}
        <div className="px-8 py-6 space-y-4">

          <div className="flex gap-4 items-start p-4 rounded-lg bg-secondary/40 border border-border/50">
            <div className="w-8 h-8 rounded-md bg-primary/10 border border-primary/20 flex items-center justify-center flex-shrink-0 mt-0.5">
              <Database className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-semibold text-foreground mb-1">Step 1 — Index your drive</p>
              <p className="text-xs text-muted-foreground leading-relaxed">
                Go to <span className="text-foreground font-medium">Settings → Index Engine</span>, confirm your drive path, and click <span className="text-foreground font-medium">Quick Update</span> to start building the search index. This runs in the background.
              </p>
            </div>
          </div>

          <div className="flex gap-4 items-start p-4 rounded-lg bg-secondary/40 border border-border/50">
            <div className="w-8 h-8 rounded-md bg-primary/10 border border-primary/20 flex items-center justify-center flex-shrink-0 mt-0.5">
              <Search className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-semibold text-foreground mb-1">Step 2 — Start searching</p>
              <p className="text-xs text-muted-foreground leading-relaxed">
                Once the index finishes, just type any word or filename in the search bar. Results appear instantly across all your files. Typos are handled automatically.
              </p>
            </div>
          </div>

        </div>

        {/* Actions */}
        <div className="px-8 pb-8 flex flex-col gap-3">
          <Button
            className="w-full gap-2"
            onClick={handleOpenSettings}
          >
            Open Settings to get started
            <ArrowRight className="w-4 h-4" />
          </Button>
          <Button
            variant="ghost"
            className="w-full text-muted-foreground hover:text-foreground"
            onClick={handleStartExploring}
          >
            Skip for now — I'm already set up
          </Button>
        </div>

        {/* Close button */}
        <button
          onClick={handleStartExploring}
          className="absolute top-4 right-4 p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
          aria-label="Close"
        >
          <X className="w-4 h-4" />
        </button>

      </div>
    </div>
  );
}
