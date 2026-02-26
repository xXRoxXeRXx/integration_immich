/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { getTimeline, getAlbums, getAlbum, getPeople, getMapMarkers, getExplore } from '../services/api.js'

export const useImmichStore = defineStore('immich', {
	state: () => ({
		// Timeline
		timelineBuckets: [],
		timelineAssets: {},
		// Filtered timelines (keyed by assetType: 'IMAGE' | 'VIDEO')
		filteredBuckets: { IMAGE: [], VIDEO: [] },
		filteredAssets: { IMAGE: {}, VIDEO: {} },
		// Albums
		albums: [],
		currentAlbum: null,
		// People list
		people: [],
		// Person detail — lazy-loaded buckets (same pattern as timeline)
		currentPersonId: null,
		personBuckets: [],
		personBucketAssets: {},
		// Map
		mapMarkers: [],
		// Explore
		exploreData: [],
		// UI
		loading: false,
		error: null,
		// Lightbox
		lightbox: {
			visible: false,
			assets: [],
			currentIndex: 0,
		},
		// Selection
		selectedAssetIds: new Set(),
		isSelectionMode: false,
	}),

	actions: {
		// ---- Timeline ----

		async fetchTimelineBuckets() {
			this.loading = true
			this.error = null
			try {
				const response = await getTimeline()
				this.timelineBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchTimelineBucket(timeBucket) {
			if (this.timelineAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ timeBucket })
				this.timelineAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadTimelineBucket(timeBucket) {
			delete this.timelineAssets[timeBucket]
		},

		// ---- Filtered timelines (Fotos / Videos) ----

		async fetchFilteredBuckets(assetType) {
			this.loading = true
			this.error = null
			try {
				// Immich's timeline/buckets endpoint does not support assetType filtering.
				// Reuse the main timeline bucket structure; PHP will filter the asset content.
				if (this.timelineBuckets.length === 0) {
					const response = await getTimeline()
					this.timelineBuckets = Array.isArray(response.data) ? response.data : []
				}
				this.filteredBuckets[assetType] = this.timelineBuckets.map(b => {
					const copy = { ...b }
					const loaded = this.filteredAssets[assetType][b.timeBucket]
					if (loaded) copy.count = loaded.length
					return copy
				})
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchFilteredBucket(assetType, timeBucket) {
			if (this.filteredAssets[assetType][timeBucket]) return
			try {
				// Pass assetType so PHP backend can filter the returned assets by type
				const response = await getTimeline({ assetType, timeBucket })
				const assets = Array.isArray(response.data) ? response.data : []
				this.filteredAssets[assetType][timeBucket] = assets
				// Update bucket count to match actual filtered asset count for correct height estimation
				const bucket = this.filteredBuckets[assetType].find(b => b.timeBucket === timeBucket)
				if (bucket) {
					bucket.count = assets.length
				}
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadFilteredBucket(assetType, timeBucket) {
			delete this.filteredAssets[assetType][timeBucket]
		},

		// ---- Albums ----

		async fetchAlbums() {
			this.loading = true
			this.error = null
			try {
				const response = await getAlbums()
				this.albums = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchAlbum(id) {
			this.loading = true
			this.error = null
			try {
				const response = await getAlbum(id)
				this.currentAlbum = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- People list ----

		async fetchPeople() {
			this.loading = true
			this.error = null
			try {
				const response = await getPeople()
				this.people = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Person detail (lazy buckets) ----

		async fetchPersonBuckets(id) {
			this.loading = true
			this.error = null
			this.currentPersonId = id
			this.personBuckets = []
			this.personBucketAssets = {}
			try {
				const response = await getTimeline({ personId: id })
				this.personBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchPersonBucketAsset(personId, timeBucket) {
			if (this.personBucketAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ personId, timeBucket })
				this.personBucketAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadPersonBucketAsset(timeBucket) {
			delete this.personBucketAssets[timeBucket]
		},

		// ---- Map ----

		async fetchMapMarkers() {
			this.loading = true
			this.error = null
			try {
				const response = await getMapMarkers()
				this.mapMarkers = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Explore ----

		async fetchExplore() {
			this.loading = true
			this.error = null
			try {
				const response = await getExplore()
				this.exploreData = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Lightbox ----

		openLightbox(assets, index = 0) {
			this.lightbox.assets = assets
			this.lightbox.currentIndex = index
			this.lightbox.visible = true
		},

		closeLightbox() {
			this.lightbox.visible = false
			this.lightbox.assets = []
			this.lightbox.currentIndex = 0
		},

		lightboxNext() {
			if (this.lightbox.currentIndex < this.lightbox.assets.length - 1) {
				this.lightbox.currentIndex++
			}
		},

		lightboxPrev() {
			if (this.lightbox.currentIndex > 0) {
				this.lightbox.currentIndex--
			}
		},

		// ---- Selection ----

		enterSelectionMode() {
			this.isSelectionMode = true
		},

		toggleAssetSelection(id) {
			const updated = new Set(this.selectedAssetIds)
			if (updated.has(id)) {
				updated.delete(id)
			} else {
				updated.add(id)
			}
			this.selectedAssetIds = updated
		},

		clearSelection() {
			this.selectedAssetIds = new Set()
			this.isSelectionMode = false
		},
	},
})
