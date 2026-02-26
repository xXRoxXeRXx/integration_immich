<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="album-detail">
		<NcLoadingIcon v-if="store.loading && !store.currentAlbum"
			:size="64"
			class="album-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="store.currentAlbum">
			<div class="album-detail__header">
				<NcButton variant="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Zurück') }}
				</NcButton>
				<div class="album-detail__title">
					<h2>{{ store.currentAlbum.albumName }}</h2>
					<span class="album-detail__count">
						{{ t('integration_immich', '{count} Bilder', { count: store.currentAlbum.assets?.length || 0 }) }}
					</span>
				</div>
			</div>

			<NcEmptyContent v-if="!store.currentAlbum.assets || store.currentAlbum.assets.length === 0"
				:name="t('integration_immich', 'Album leer')"
				:description="t('integration_immich', 'Dieses Album enthält keine Bilder.')">
				<template #icon>
					<ImageIcon :size="64" />
				</template>
			</NcEmptyContent>

			<PhotoGrid v-else
				:assets="store.currentAlbum.assets"
				:selectable="true"
				@click="(asset, idx) => store.openLightbox(store.currentAlbum.assets, idx)" />
		</template>
	</div>
</template>

<script setup>
import { onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const store = useImmichStore()
const router = useRouter()

function goBack() {
	router.push({ name: 'albums' })
}

function loadAlbum() {
	store.fetchAlbum(props.id)
}

onMounted(() => {
	loadAlbum()
})

watch(() => props.id, () => {
	loadAlbum()
})
</script>

<style scoped>
.album-detail {
	height: 100%;
	overflow-y: auto;
	box-sizing: border-box;
	padding: 16px 16px 16px 52px;
}

.album-detail__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.album-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 16px;
	padding: 0 8px;
}

.album-detail__title {
	flex: 1;
}

.album-detail__title h2 {
	margin: 0;
	font-size: 22px;
}

.album-detail__count {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}
</style>
