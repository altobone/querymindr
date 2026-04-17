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
import TrialReminderModal, { shouldShowReminder, dismissReminder } from "@/components/trial-reminder-modal";

const queryClient = new QueryClient();

interface LicenseStatus {
  licensed: boolean;
  daysRemaining: number;
  installDate: string;
}

function Router({ licenseStatus, onActivated }: { licenseStatus: LicenseStatus | null; onActivated: () => void }) {
  const [showWelcome, setShowWelcome] = useState(() => !hasSeenWelcome());

  // Trial expired gate — also triggered by ?preview=expired for styling review
  const forcePreview = new URLSearchParams(window.location.search).get("preview") === "expired";
  if (forcePreview || (licenseStatus && !licenseStatus.licensed && licenseStatus.daysRemaining === 0)) {
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
  const previewReminder = new URLSearchParams(window.location.search).get("preview") === "reminder";
  const [showReminder, setShowReminder] = useState(previewReminder);

  const fetchLicense = () => {
    fetch("/api/querymindr/license/status")
      .then(r => r.json())
      .then(d => {
        const status = d as LicenseStatus;
        setLicenseStatus(status);
        if (!status.licensed && shouldShowReminder(status.daysRemaining)) {
          setShowReminder(true);
        }
      })
      .catch(() => {});
  };

  useEffect(() => {
    fetchLicense();
    const interval = setInterval(fetchLicense, 60 * 60 * 1000);
    return () => clearInterval(interval);
  }, []);

  const handleDismissReminder = () => {
    dismissReminder();
    setShowReminder(false);
  };

  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <WouterRouter base={import.meta.env.BASE_URL.replace(/\/$/, "")}>
          <Router licenseStatus={licenseStatus} onActivated={fetchLicense} />
        </WouterRouter>
        <Toaster />
        {showReminder && (
          <TrialReminderModal
            daysRemaining={previewReminder ? 2 : (licenseStatus?.daysRemaining ?? 2)}
            onDismiss={handleDismissReminder}
          />
        )}
      </TooltipProvider>
    </QueryClientProvider>
  );
}

export default App;
