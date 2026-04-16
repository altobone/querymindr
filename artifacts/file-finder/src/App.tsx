import { useState, useEffect } from "react";
import { Switch, Route, Router as WouterRouter } from "wouter";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Toaster } from "@/components/ui/toaster";
import { TooltipProvider } from "@/components/ui/tooltip";
import NotFound from "@/pages/not-found";
import Layout from "@/components/layout";
import SearchPage from "@/pages/search";
import DuplicatesPage from "@/pages/duplicates";
import SettingsPage from "@/pages/settings";
import HelpPage from "@/pages/help";
import WelcomeScreen, { hasSeenWelcome } from "@/pages/welcome";
import TrialExpiredPage from "@/pages/trial-expired";

const queryClient = new QueryClient();

interface LicenseStatus {
  licensed: boolean;
  daysRemaining: number;
  installDate: string;
}

function Router({ licenseStatus, onActivated }: { licenseStatus: LicenseStatus | null; onActivated: () => void }) {
  const [showWelcome, setShowWelcome] = useState(() => !hasSeenWelcome());

  // Trial expired gate
  if (licenseStatus && !licenseStatus.licensed && licenseStatus.daysRemaining === 0) {
    return <TrialExpiredPage onActivated={onActivated} />;
  }

  return (
    <>
      <Layout licenseStatus={licenseStatus}>
        <Switch>
          <Route path="/" component={SearchPage} />
          <Route path="/duplicates" component={DuplicatesPage} />
          <Route path="/settings" component={SettingsPage} />
          <Route path="/help" component={HelpPage} />
          <Route component={NotFound} />
        </Switch>
      </Layout>
      {showWelcome && (
        <WelcomeScreen onDismiss={() => setShowWelcome(false)} />
      )}
    </>
  );
}

function App() {
  const [licenseStatus, setLicenseStatus] = useState<LicenseStatus | null>(null);

  const fetchLicense = () => {
    fetch("/api/querymindr/license/status")
      .then(r => r.json())
      .then(d => setLicenseStatus(d as LicenseStatus))
      .catch(() => {});
  };

  useEffect(() => {
    fetchLicense();
    // Re-check once per hour in case trial expires mid-session
    const interval = setInterval(fetchLicense, 60 * 60 * 1000);
    return () => clearInterval(interval);
  }, []);

  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <WouterRouter base={import.meta.env.BASE_URL.replace(/\/$/, "")}>
          <Router licenseStatus={licenseStatus} onActivated={fetchLicense} />
        </WouterRouter>
        <Toaster />
      </TooltipProvider>
    </QueryClientProvider>
  );
}

export default App;
