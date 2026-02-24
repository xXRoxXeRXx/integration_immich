# Immich Integration for Nextcloud

A Nextcloud app that integrates your [Immich](https://immich.app) photo library directly into Nextcloud.

## Features

- **Timeline** — Browse your full photo/video timeline with lazy-loading buckets
- **Albums** — View all Immich albums with thumbnails
- **People** — Face recognition — browse photos by person
- **Map** — View geotagged photos on an interactive map
- **Explore** — Browse photos by location and category
- **Lightbox** — Full-screen image/video viewer with keyboard navigation
- **Upload** — Upload files from Nextcloud Files directly to Immich
- **Admin settings** — Configure the Immich server URL and API key

## Requirements

- Nextcloud 30+
- PHP 8.1+
- A running [Immich](https://immich.app) instance

## Installation

### From source

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/xXRoxXeRXx/integration_immich
cd integration_immich
npm ci
npm run build
```

Then enable the app in Nextcloud:

```
occ app:enable integration_immich
```

## Configuration

1. Go to **Nextcloud Admin Settings → Immich Integration**
2. Enter your Immich server URL (e.g. `https://photos.example.com`)
3. Enter your Immich API key (Settings → API Keys in Immich)
4. Save — the Immich tab will appear in the navigation

## Development

```bash
npm install
npm run dev      # development build
npm run watch    # watch mode
npm run build    # production build
```

## License

[AGPL-3.0-or-later](COPYING)
