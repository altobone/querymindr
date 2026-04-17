import { X, ShoppingCart, Clock } from "lucide-react";
import { Button } from "@/components/ui/button";

const CHECKOUT_URL_MAC     = "https://musicsavvy.com/checkout/querymindr-for-mac/step/querymindr-for-mac";
const CHECKOUT_URL_WINDOWS = "https://musicsavvy.com/checkout/querymindr-for-windows/step/querymindr-for-windows";
const CHECKOUT_URL_LINUX   = "https://musicsavvy.com/checkout/querymindr-for-linux/step/querymindr-for-linux";

function detectPlatform(): "mac" | "windows" | "linux" {
  const p = navigator.platform?.toLowerCase() ?? "";
  const ua = navigator.userAgent?.toLowerCase() ?? "";
  if (p.includes("win") || ua.includes("windows")) return "windows";
  if (p.includes("linux") || ua.includes("linux")) return "linux";
  return "mac";
}

const PLATFORM_INFO: Record<"mac" | "windows" | "linux", { label: string; url: string }> = {
  mac:     { label: "Buy for Mac — $29",     url: CHECKOUT_URL_MAC },
  windows: { label: "Buy for Windows — $29", url: CHECKOUT_URL_WINDOWS },
  linux:   { label: "Buy for Linux — $29",   url: CHECKOUT_URL_LINUX },
};

const STORAGE_KEY = "querymindr-reminder-last-day";

export function shouldShowReminder(daysRemaining: number): boolean {
  if (daysRemaining !== 2) return false;
  return localStorage.getItem(STORAGE_KEY) !== "shown";
}

export function dismissReminder() {
  localStorage.setItem(STORAGE_KEY, "shown");
}

interface Props {
  daysRemaining: number;
  onDismiss: () => void;
}

export default function TrialReminderModal({ daysRemaining, onDismiss }: Props) {
  const platform = detectPlatform();
  const { label, url } = PLATFORM_INFO[platform];

  const handleBuy = () => {
    onDismiss();
    window.open(url, "_blank");
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.6)", backdropFilter: "blur(4px)" }}>
      <div
        className="relative w-full max-w-sm rounded-xl p-6 shadow-2xl"
        style={{ background: "#111", border: "1px solid rgba(232,255,71,0.3)" }}
      >
        {/* Dismiss X */}
        <button
          onClick={onDismiss}
          className="absolute top-4 right-4 text-gray-500 hover:text-gray-300 transition-colors"
          aria-label="Dismiss"
        >
          <X className="w-4 h-4" />
        </button>

        {/* Icon + heading */}
        <div className="flex items-center gap-3 mb-4">
          <div
            className="w-10 h-10 rounded-lg flex items-center justify-center shrink-0"
            style={{ background: "rgba(232,255,71,0.1)", border: "1px solid rgba(232,255,71,0.25)" }}
          >
            <Clock className="w-5 h-5" style={{ color: "#e8ff47" }} />
          </div>
          <div>
            <p className="font-semibold text-white text-sm">
              {daysRemaining === 1 ? "1 day left in your trial" : `${daysRemaining} days left in your trial`}
            </p>
            <p className="text-xs text-gray-400 mt-0.5">Your 7-day trial is almost up.</p>
          </div>
        </div>

        <p className="text-sm text-gray-400 mb-5 leading-relaxed">
          Keep Querymindr after your trial — one-time payment, no subscription, yours forever.
        </p>

        <div className="space-y-2">
          <Button
            className="w-full font-medium"
            style={{ background: "#e8ff47", color: "#0d0d0d" }}
            onMouseEnter={e => (e.currentTarget.style.background = "#d4eb3a")}
            onMouseLeave={e => (e.currentTarget.style.background = "#e8ff47")}
            onClick={handleBuy}
          >
            <ShoppingCart className="w-4 h-4 mr-2" />
            {label}
          </Button>
          <Button
            variant="ghost"
            className="w-full text-gray-500 hover:text-gray-300 text-sm"
            onClick={onDismiss}
          >
            Remind me tomorrow
          </Button>
        </div>
      </div>
    </div>
  );
}
