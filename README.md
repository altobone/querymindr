# Querymindr

**Fast, private search for files on your Mac.**

Querymindr is a local file search and discovery tool designed for large external drives and large collections of files.

## Free and open source

Querymindr is free to use. There is no subscription and no license key.

The application runs locally on your Mac. Your indexed file information stays on your computer.

## Features

- Fast file search across large drives and folders
- Index external drives and any folders you choose
- Quick Update to keep your index current
- Full Re-index when you need to rebuild an index
- Duplicate detection
- Local-first and private
- Optional AI assistance using your own Anthropic API key
- macOS menu-bar app
- Runs in the background after installation

## Requirements

- macOS
- Intel or Apple Silicon Mac

No Node.js installation is required for normal use. No Terminal commands are required to install or use Querymindr.

## Installation

Download the latest `Querymindr-install-mac.pkg` from the GitHub Releases page. Double-click the `.pkg` file and follow the macOS installer.

After installation, Querymindr opens automatically in your browser at `http://localhost:8080/querymindr/`.

## Getting started

Open **Settings → Index Engine**, add the drives or folders you want Querymindr to search, and click **Quick Update**.

Then go to **Search** and search for files in your indexed locations.

## Duplicate detection

Open **Duplicates** to find duplicate files within your indexed locations.

## Optional AI features

Querymindr can optionally use Anthropic’s API for AI-assisted file discovery and analysis. Basic file indexing and search do not require AI.

## Privacy

Querymindr runs locally on your Mac. Your indexed file information is stored locally. Querymindr does not require a cloud account or upload your files to a central Querymindr server.

## Building from source

```bash
pnpm install
pnpm --filter @workspace/file-finder run build
pnpm --filter @workspace/api-server run build
./create-release.sh
```

## Project status

Querymindr is being released as free, open-source software focused on practical local file search for Mac users.

Contributions, bug reports, and suggestions are welcome.

## License

Querymindr is released under the [GNU General Public License v3.0](LICENSE).
