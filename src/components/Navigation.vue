<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :name="t('integration_immich', 'Alle Bilder')"
				:to="{ name: 'timeline' }"
				:active="$route.name === 'timeline'">
				<template #icon>
					<ImageIcon :size="20" />
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

import ImageIcon from 'vue-material-design-icons/Image.vue'
import FolderIcon from 'vue-material-design-icons/FolderMultipleImage.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import MapIcon from 'vue-material-design-icons/Map.vue'
import CompassIcon from 'vue-material-design-icons/Compass.vue'
</script>
