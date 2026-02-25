<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="integration_immich">
		<Navigation />
		<NcAppContent>
			<div class="view-content">
				<!-- Sticky toolbar — inside scroll container like NC Photos -->
				<div class="view-toolbar">
					<h2 class="view-toolbar__title">{{ pageTitle }}</h2>
				</div>
				<div class="view-page">
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
/* Let NC handle app-content layout natively, only prevent outer scroll */
:deep(.app-content) {
	overflow: hidden;
}

/* Single scroll container — full height, scrolls internally */
.view-content {
	height: 100%;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
}

/* Sticky toolbar — same pattern as NC Photos HeaderNavigation */
.view-toolbar {
	position: sticky;
	top: 0;
	z-index: 20;
	flex-shrink: 0;
	min-height: var(--default-clickable-area, 44px);
	display: flex;
	align-items: center;
	/* left: clear the nav toggle button; block: standard nav padding */
	padding-inline-start: calc(var(--default-clickable-area, 44px) + 2 * var(--app-navigation-padding, 4px));
	padding-inline-end: var(--app-navigation-padding, 4px);
	padding-block: var(--app-navigation-padding, 4px);
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.view-toolbar__title {
	font-size: 20px;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* View container — fills remaining space below toolbar */
.view-page {
	flex: 1;
	min-height: 0;
	overflow: hidden;
}
</style>
