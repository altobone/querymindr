import { useState } from "react";
import { KeyRound, ShoppingCart, CheckCircle2, AlertCircle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const CHECKOUT_URL_MAC     = "https://musicsavvy.com/?add-to-cart=54669";
const CHECKOUT_URL_WINDOWS = "https://musicsavvy.com/?add-to-cart=54663";
const CHECKOUT_URL_LINUX   = "https://musicsavvy.com/?add-to-cart=54668";

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

interface Props {
  onActivated: () => void;
}

export default function TrialExpiredPage({ onActivated }: Props) {
  const platform = detectPlatform();
  const { label, url } = PLATFORM_INFO[platform];

  const [key, setKey]       = useState("");
  const [status, setStatus] = useState<"idle" | "loading" | "success" | "error">("idle");
  const [message, setMessage] = useState("");

  const handleActivate = async () => {
    if (!key.trim()) return;
    setStatus("loading");
    setMessage("");
    try {
      const res = await fetch("/api/querymindr/license/activate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ key: key.trim() }),
      });
      const data = await res.json() as { ok: boolean; message: string };
      if (data.ok) {
        setStatus("success");
        setMessage(data.message);
        setTimeout(() => onActivated(), 1500);
      } else {
        setStatus("error");
        setMessage(data.message);
      }
    } catch {
      setStatus("error");
      setMessage("Could not connect to the server. Make sure Querymindr is running.");
    }
  };

  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center p-6">
      <div className="w-full max-w-lg space-y-6">

        {/* Header */}
        <div className="text-center space-y-2">
          <div
            className="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-2"
            style={{ background: "rgba(232,255,71,0.1)", border: "1px solid rgba(232,255,71,0.2)" }}
          >
            <KeyRound className="w-8 h-8" style={{ color: "#e8ff47" }} />
          </div>
          <h1 className="text-2xl font-bold text-white">Your trial has ended</h1>
          <p className="text-gray-400 text-sm leading-relaxed">
            Thanks for trying Querymindr. Purchase a license to keep searching your files — one-time payment, yours forever.
          </p>
        </div>

        {/* Buy card */}
        <Card className="bg-gray-900" style={{ borderColor: "rgba(232,255,71,0.3)" }}>
          <CardHeader className="pb-3">
            <CardTitle className="text-white text-lg flex items-center gap-2">
              <ShoppingCart className="w-5 h-5" style={{ color: "#e8ff47" }} />
              Querymindr — $29 one-time
            </CardTitle>
            <CardDescription className="text-gray-400">
              Instant download. No subscription. Works on your drive, your computer, fully offline.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <ul className="text-sm text-gray-400 space-y-1.5">
              {[
                "Search 10TB+ drives in under a second",
                "Find duplicates by file content, not just name",
                "Optional AI-powered natural-language search",
                "Runs completely offline — your files never leave your computer",
              ].map(f => (
                <li key={f} className="flex items-start gap-2">
                  <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0" style={{ color: "#e8ff47" }} />
                  <span>{f}</span>
                </li>
              ))}
            </ul>
            <Button
              className="w-full font-medium"
              style={{ background: "#e8ff47", color: "#0d0d0d" }}
              onMouseEnter={e => (e.currentTarget.style.background = "#d4eb3a")}
              onMouseLeave={e => (e.currentTarget.style.background = "#e8ff47")}
              onClick={() => window.open(url, "_blank")}
            >
              <ShoppingCart className="w-4 h-4 mr-2" />
              {label}
            </Button>
            <p className="text-xs text-gray-500 text-center">
              Wrong platform?{" "}
              {(["mac", "windows", "linux"] as const)
                .filter(p => p !== platform)
                .map((p, i, arr) => (
                  <span key={p}>
                    <a
                      href={PLATFORM_INFO[p].url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="hover:underline"
                      style={{ color: "#e8ff47" }}
                    >
                      {p.charAt(0).toUpperCase() + p.slice(1)}
                    </a>
                    {i < arr.length - 1 ? " · " : ""}
                  </span>
                ))}
            </p>
          </CardContent>
        </Card>

        {/* License key entry */}
        <Card className="bg-gray-900 border-gray-700/50">
          <CardHeader className="pb-3">
            <CardTitle className="text-white text-base">Already purchased?</CardTitle>
            <CardDescription className="text-gray-400 text-sm">
              Enter the license key from your order confirmation email.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input
              placeholder="QMDR-XXXX-XXXX-XXXX-XXXX"
              value={key}
              onChange={e => { setKey(e.target.value.toUpperCase()); setStatus("idle"); }}
              onKeyDown={e => e.key === "Enter" && handleActivate()}
              className="bg-gray-800 border-gray-600 text-white font-mono tracking-widest placeholder:tracking-normal placeholder:font-sans"
              disabled={status === "loading" || status === "success"}
            />

            {status === "error" && (
              <div className="flex items-center gap-2 text-sm text-red-400">
                <AlertCircle className="w-4 h-4 shrink-0" />
                {message}
              </div>
            )}
            {status === "success" && (
              <div className="flex items-center gap-2 text-sm text-green-400">
                <CheckCircle2 className="w-4 h-4 shrink-0" />
                {message} Unlocking…
              </div>
            )}

            <Button
              className="w-full"
              variant="outline"
              onClick={handleActivate}
              disabled={!key.trim() || status === "loading" || status === "success"}
            >
              {status === "loading" ? (
                <><Loader2 className="w-4 h-4 mr-2 animate-spin" /> Activating…</>
              ) : (
                <><KeyRound className="w-4 h-4 mr-2" /> Activate License</>
              )}
            </Button>
          </CardContent>
        </Card>

      </div>
    </div>
  );
}
