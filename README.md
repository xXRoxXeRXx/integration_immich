<div align="center">

<img src="img/app-dark.svg" width="80" alt="Immich Integration">

# Immich Integration for Nextcloud

**Browse your [Immich](https://immich.app) photo library directly inside Nextcloud.**  
Timeline, albums, people, map, explore — all seamlessly integrated.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](COPYING)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-30%2B-0082C9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Immich](https://img.shields.io/badge/Immich-compatible-4AC37C)](https://immich.app)

</div>

---

## 📸 Screenshots

<table>
  <tr>
    <td align="center"><strong>Timeline</strong></td>
    <td align="center"><strong>Albums</strong></td>
    <td align="center"><strong>People</strong></td>
  </tr>
  <tr>
    <td><img src="screenshots/allmedia.png" alt="Timeline view" width="280"></td>
    <td><img src="screenshots/album.png" alt="Albums view" width="280"></td>
    <td><img src="screenshots/people.png" alt="People view" width="280"></td>
  </tr>
</table>

---

## ✨ Features

| Feature | Description |
| --- | --- |
| 🖼️ **Timeline** | Lazy-loaded photo & video timeline, grouped by date with smooth infinite scroll |
| 📁 **Albums** | Browse all your Immich albums with cover thumbnails, create, rename and delete albums |
| 👤 **People** | Face recognition — explore your library by recognized person |
| 🗺️ **Map** | Interactive map of all geotagged photos with cluster markers |
| 🔍 **Explore** | Browse by city, country, state, object or tag |
| 🔎 **Lightbox** | Full-screen viewer with keyboard navigation, pinch-to-zoom and EXIF metadata panel |
| ⭐ **Favorites** | Mark and unmark photos as favorites directly from Nextcloud |
| 💾 **Save to Nextcloud** | Select photos and videos and save the originals directly to your Nextcloud Files |
| ⬆️ **Upload to Immich** | Send photos and videos from Nextcloud Files to Immich via the file action menu |
| ☑️ **Multi-select** | Select multiple assets across any view for batch operations |
| 🌍 **Translations** | Full German translation included, more languages via Transifex |
| ⚙️ **Admin Settings** | Configure Immich server URL and API key per user |

---

## 🔧 Requirements

- **Nextcloud** 30 or newer
- **PHP** 8.1 or newer
- A running [Immich](https://immich.app) instance (with API access enabled)

---

## 🚀 Installation

### Via custom_apps (manual)

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/xXRoxXeRXx/integration_immich
cd integration_immich
npm ci && npm run build
php occ app:enable integration_immich
```

---

## ⚙️ Configuration

1. Open **Nextcloud → Personal Settings → Immich Integration**
2. Enter your **Immich server URL** (e.g. `https://photos.example.com`)
3. Enter your **API key** — found in Immich under *Account Settings → API Keys*
4. Click **Test connection** to verify, then **Save**
5. The **Immich** entry now appears in the Nextcloud top navigation

> Admin-level defaults can also be set under **Admin Settings → Immich Integration**.

---

## 🛠️ Development

```bash
npm install
npm run dev      # development build (unminified)
npm run watch    # watch mode with hot rebuild
npm run build    # production build
```

The app uses **Vue 3** + **Pinia** for state management and the official `@nextcloud/*` component libraries.

---

## 🤝 Contributing

Pull requests and bug reports are welcome!  
Please open an [issue](https://github.com/xXRoxXeRXx/integration_immich/issues) for feature requests or bug reports.

---

## 📄 License

This project is licensed under the [AGPL-3.0-or-later](COPYING) license.
