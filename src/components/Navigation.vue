<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :name="t('integration_immich', 'Alle Medien')"
				:to="{ name: 'timeline' }"
				:active="$route.name === 'timeline'">
				<template #icon>
					<ImageIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Fotos')"
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
			<NcAppNavigationItem :name="t('integration_immich', 'Favoriten')"
				:to="{ name: 'favorites' }"
				:active="$route.name === 'favorites'">
				<template #icon>
					<HeartIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Alben')"
				:to="{ name: 'albums' }"
				:active="$route.name === 'albums' || $route.name === 'album-detail'">
				<template #icon>
					<FolderIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Personen')"
				:to="{ name: 'people' }"
				:active="$route.name === 'people' || $route.name === 'person-detail'">
				<template #icon>
					<AccountGroupIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Karte')"
				:to="{ name: 'map' }"
				:active="$route.name === 'map'">
				<template #icon>
					<MapIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :name="t('integration_immich', 'Erkunden')"
				:to="{ name: 'explore' }"
				:active="$route.name === 'explore' || $route.name === 'place-detail'">
				<template #icon>
					<CompassIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
	</NcAppNavigation>
</template>

<script setup>
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

</script>
