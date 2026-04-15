import { Link, useLocation } from "wouter";
import { Search, Copy, Settings, HelpCircle } from "lucide-react";
import { cn } from "@/lib/utils";
import logo from "@assets/Queryminder-logo_white_1776223766599.png";

export default function Layout({ children }: { children: React.ReactNode }) {
  const [location] = useLocation();

  const navItems = [
    { name: "Search", href: "/", icon: Search },
    { name: "Duplicates", href: "/duplicates", icon: Copy },
    { name: "Settings", href: "/settings", icon: Settings },
    { name: "Help", href: "/help", icon: HelpCircle },
  ];

  return (
    <div className="flex h-screen w-full bg-background overflow-hidden selection:bg-primary selection:text-primary-foreground">
      <div className="w-64 border-r border-border bg-card flex flex-col justify-between flex-shrink-0">
        <div className="flex flex-col h-full">
          <div className="px-6 py-5 border-b border-border/50">
            <img src={logo} alt="Querymindr" className="h-7 w-auto" />
          </div>
          
          <nav className="flex-1 px-3 py-6 space-y-1 overflow-y-auto">
            <div className="text-xs font-medium text-muted-foreground px-3 mb-3 uppercase tracking-wider">Menu</div>
            {navItems.map((item) => {
              const isActive = location === item.href;
              const Icon = item.icon;
              return (
                <Link
                  key={item.name}
                  href={item.href}
                  className={cn(
                    "flex items-center gap-3 px-3 py-2.5 rounded-md text-sm transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    isActive
                      ? "bg-secondary text-secondary-foreground font-medium"
                      : "text-muted-foreground hover:text-foreground hover:bg-secondary/50"
                  )}
                >
                  <Icon className={cn("w-4 h-4", isActive ? "text-foreground" : "text-muted-foreground")} />
                  {item.name}
                </Link>
              );
            })}
          </nav>
        </div>
      </div>
      <main className="flex-1 flex flex-col overflow-hidden relative">
        {children}
      </main>
    </div>
  );
}
