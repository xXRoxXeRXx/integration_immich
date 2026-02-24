<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="integration_immich">
		<Navigation />
		<NcAppContent>
			<div class="view-wrapper">
				<div class="view-header">
					<h2 class="view-header__title">{{ pageTitle }}</h2>
				</div>
				<div class="view-content">
					<router-view />
				</div>
			</div>
		</NcAppContent>
	</NcContent>
	<LightboxView />
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { NcContent, NcAppContent } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import Navigation from './components/Navigation.vue'
import LightboxView from './components/LightboxView.vue'

const route = useRoute()

const pageTitles = {
	'timeline': t('integration_immich', 'Alle Bilder'),
	'albums': t('integration_immich', 'Alben'),
	'album-detail': t('integration_immich', 'Alben'),
	'people': t('integration_immich', 'Personen'),
	'person-detail': t('integration_immich', 'Personen'),
	'map': t('integration_immich', 'Karte'),
	'explore': t('integration_immich', 'Erkunden'),
	'place-detail': t('integration_immich', 'Erkunden'),
}

const pageTitle = computed(() => pageTitles[route.name] ?? 'Immich')
</script>

<style scoped>
/* app-content fills height, no own scroll — wrapper handles it */
:deep(.app-content) {
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.view-wrapper {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

.view-header {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	/* push title past the nav toggle button (~44px) */
	padding: 0 20px 0 calc(var(--default-clickable-area, 44px) + 8px);
	height: var(--default-clickable-area, 44px);
}

.view-header__title {
	font-size: 20px;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.view-content {
	flex: 1;
	overflow-y: auto;
}
</style>
