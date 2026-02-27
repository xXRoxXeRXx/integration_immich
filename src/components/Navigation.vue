<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :name="t('integration_immich', 'All media')"
				:to="{ name: 'timeline' }"
				:active="$route.name === 'timeline'">
				<template #icon>
					<ImageIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Photos')"
				:to="{ name: 'photos' }"
				:active="$route.name === 'photos'">
				<template #icon>
					<PhotosIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Videos')"
				:to="{ name: 'videos' }"
				:active="$route.name === 'videos'">
				<template #icon>
					<VideoIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Favorites')"
				:to="{ name: 'favorites' }"
				:active="$route.name === 'favorites'">
				<template #icon>
					<HeartIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Albums')"
				:to="{ name: 'albums' }"
				:active="$route.name === 'albums' || $route.name === 'album-detail'">
				<template #icon>
					<FolderIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'People')"
				:to="{ name: 'people' }"
				:active="$route.name === 'people' || $route.name === 'person-detail'">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Map')"
				:to="{ name: 'map' }"
				:active="$route.name === 'map'">
				<template #icon>
					<MapIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Explore')"
				:to="{ name: 'explore' }"
				:active="$route.name === 'explore' || $route.name === 'place-detail'">
				<template #icon>
					<CompassIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>

		<!-- Footer: link to Immich instance -->
		<template #footer>
			<NcAppNavigationItem v-if="immichUrl"
				:name="t('integration_immich', 'Open Immich')"
				:href="immichUrl"
				target="_blank"
				rel="noopener noreferrer">
				<template #icon>
					<OpenInNewIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script setup>
import { loadState } from '@nextcloud/initial-state'
import { NcAppNavigation, NcAppNavigationList, NcAppNavigationItem } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

import ImageIcon from 'vue-material-design-icons/ImageFrame.vue'
import PhotosIcon from 'vue-material-design-icons/ImageOutline.vue'
import VideoIcon from 'vue-material-design-icons/PlayCircleOutline.vue'
import HeartIcon from 'vue-material-design-icons/HeartOutline.vue'
import FolderIcon from 'vue-material-design-icons/ViewGalleryOutline.vue'
import AccountGroupIcon from 'vue-material-design-icons/FaceWomanShimmerOutline.vue'
import MapIcon from 'vue-material-design-icons/MapOutline.vue'
import CompassIcon from 'vue-material-design-icons/Telescope.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

const config = loadState('integration_immich', 'user-config', {})
const rawUrl = config?.server_url || ''
const immichUrl = rawUrl.startsWith('http://') || rawUrl.startsWith('https://') ? rawUrl : ''
</script>
