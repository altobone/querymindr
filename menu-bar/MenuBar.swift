import Cocoa
import Foundation

// ---------------------------------------------------------------
// Configuration — update these when rebranding
// ---------------------------------------------------------------
let APP_NAME    = "Querymindr"
let APP_URL     = "http://localhost:8080/querymindr/"
let API_BASE    = "http://localhost:8080/api/querymindr"
let POLL_SECS   = 5.0
// ---------------------------------------------------------------

class AppDelegate: NSObject, NSApplicationDelegate {
    var statusItem: NSStatusItem!
    var statusMenuItem: NSMenuItem!
    var quickUpdateItem: NSMenuItem!
    var lastUpdatedItem: NSMenuItem!
    var timer: Timer?

    func applicationDidFinishLaunching(_ notification: Notification) {
        NSApp.setActivationPolicy(.accessory)

        statusItem = NSStatusBar.system.statusItem(withLength: NSStatusItem.variableLength)

        if let button = statusItem.button {
            if let img = NSImage(systemSymbolName: "magnifyingglass", accessibilityDescription: APP_NAME) {
                img.isTemplate = true
                button.image = img
            } else {
                button.title = "🔍"
            }
            button.toolTip = APP_NAME
        }

        buildMenu()

        // Wait up to 30s for the server to come up, then start polling
        waitForServer(attempts: 30)
    }

    func buildMenu() {
        let menu = NSMenu()

        // Title
        let title = NSMenuItem(title: APP_NAME, action: nil, keyEquivalent: "")
        title.isEnabled = false
        menu.addItem(title)

        menu.addItem(.separator())

        // Status line
        statusMenuItem = NSMenuItem(title: "Checking status…", action: nil, keyEquivalent: "")
        statusMenuItem.isEnabled = false
        menu.addItem(statusMenuItem)

        // Last updated
        lastUpdatedItem = NSMenuItem(title: "", action: nil, keyEquivalent: "")
        lastUpdatedItem.isEnabled = false
        lastUpdatedItem.isHidden = true
        menu.addItem(lastUpdatedItem)

        menu.addItem(.separator())

        let openItem = NSMenuItem(title: "Open \(APP_NAME)", action: #selector(openApp), keyEquivalent: "o")
        openItem.target = self
        menu.addItem(openItem)

        quickUpdateItem = NSMenuItem(title: "Quick Update", action: #selector(quickUpdate), keyEquivalent: "u")
        quickUpdateItem.target = self
        menu.addItem(quickUpdateItem)

        menu.addItem(.separator())

        let quit = NSMenuItem(title: "Quit", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        menu.addItem(quit)

        statusItem.menu = menu
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    @objc func openApp() {
        NSWorkspace.shared.open(URL(string: APP_URL)!)
    }

    @objc func quickUpdate() {
        quickUpdateItem.title = "Starting…"
        quickUpdateItem.isEnabled = false

        var req = URLRequest(url: URL(string: "\(API_BASE)/index/incremental")!)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = "{}".data(using: .utf8)
        req.timeoutInterval = 5

        URLSession.shared.dataTask(with: req) { [weak self] _, _, _ in
            DispatchQueue.main.asyncAfter(deadline: .now() + 1) {
                self?.checkStatus()
            }
        }.resume()
    }

    // ---------------------------------------------------------------
    // Server readiness + polling
    // ---------------------------------------------------------------

    func waitForServer(attempts: Int) {
        guard attempts > 0 else {
            DispatchQueue.main.async { self.setStatus("Server not running") }
            return
        }

        let url = URL(string: "\(API_BASE)/stats")!
        URLSession.shared.dataTask(with: url) { [weak self] data, resp, _ in
            if let http = resp as? HTTPURLResponse, http.statusCode == 200 {
                DispatchQueue.main.async {
                    self?.startPolling()
                }
            } else {
                DispatchQueue.global().asyncAfter(deadline: .now() + 1) {
                    self?.waitForServer(attempts: attempts - 1)
                }
            }
        }.resume()
    }

    func startPolling() {
        checkStatus()
        timer = Timer.scheduledTimer(withTimeInterval: POLL_SECS, repeats: true) { [weak self] _ in
            self?.checkStatus()
        }
    }

    func checkStatus() {
        let url = URL(string: "\(API_BASE)/index/status")!
        URLSession.shared.dataTask(with: url) { [weak self] data, _, _ in
            guard let data = data,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
            else { return }

            DispatchQueue.main.async {
                self?.applyStatus(json)
            }
        }.resume()
    }

    func applyStatus(_ json: [String: Any]) {
        let status    = json["status"]          as? String ?? "idle"
        let type      = json["type"]            as? String ?? "full"
        let processed = json["processed_files"] as? Int    ?? 0
        let total     = json["total_files"]     as? Int    ?? 0
        let completed = json["completed_at"]    as? String

        switch status {
        case "running":
            let label = type == "incremental" ? "Quick Update" : "Full Index"
            if total > 0 {
                let pct = Int(Double(processed) / Double(total) * 100)
                setStatus("\(label): \(pct)%  (\(fmt(processed)) / \(fmt(total)))")
            } else {
                setStatus("\(label): scanning…")
            }
            quickUpdateItem.title = "Quick Update"
            quickUpdateItem.isEnabled = false
            lastUpdatedItem.isHidden = true

        case "completed":
            setStatus("Index up to date ✓")
            quickUpdateItem.title = "Quick Update"
            quickUpdateItem.isEnabled = true
            if let ts = completed {
                lastUpdatedItem.title = "Updated \(relativeTime(ts))"
                lastUpdatedItem.isHidden = false
            }

        case "failed":
            setStatus("Index error — check Settings")
            quickUpdateItem.title = "Quick Update"
            quickUpdateItem.isEnabled = true

        default:
            setStatus("Index not yet run")
            quickUpdateItem.title = "Quick Update"
            quickUpdateItem.isEnabled = true
        }
    }

    func setStatus(_ text: String) {
        statusMenuItem.title = text
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    func fmt(_ n: Int) -> String {
        let f = NumberFormatter()
        f.numberStyle = .decimal
        return f.string(from: NSNumber(value: n)) ?? "\(n)"
    }

    func relativeTime(_ iso: String) -> String {
        let df = ISO8601DateFormatter()
        df.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        var date = df.date(from: iso)
        if date == nil {
            df.formatOptions = [.withInternetDateTime]
            date = df.date(from: iso)
        }
        guard let date = date else { return iso }
        let secs = Int(-date.timeIntervalSinceNow)
        if secs < 60   { return "just now" }
        if secs < 3600 { return "\(secs / 60)m ago" }
        if secs < 86400 { return "\(secs / 3600)h ago" }
        return "\(secs / 86400)d ago"
    }
}

let app = NSApplication.shared
let delegate = AppDelegate()
app.delegate = delegate
app.run()
