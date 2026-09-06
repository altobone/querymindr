import { useState } from "react";
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

const queryClient = new QueryClient();

function Router() {
  const [showWelcome, setShowWelcome] = useState(() => !hasSeenWelcome());

  return (
    <>
      <Layout>
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
  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <WouterRouter base={import.meta.env.BASE_URL.replace(/\\/$/, "")}>
          <Router />
        </WouterRouter>
        <Toaster />
      </TooltipProvider>
    </QueryClientProvider>
  );
}

export default App;
