<template>
	<NcContent app-name="integration_immich">
		<Navigation />
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
	<LightboxView />
</template>

<script setup>
import { NcContent, NcAppContent } from '@nextcloud/vue'
import Navigation from './components/Navigation.vue'
import LightboxView from './components/LightboxView.vue'
</script>

<style scoped>
:deep(.app-content) {
	overflow-y: auto;
}
</style>
