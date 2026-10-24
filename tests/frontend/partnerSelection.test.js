/** @vitest-environment jsdom */
import { ref } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
	getTimeline: vi.fn(),
	getThumbnailUrl: vi.fn(() => '/thumbnail'),
	getPartners: vi.fn(),
	getAlbums: vi.fn(),
	getAlbum: vi.fn(),
	getPeople: vi.fn(),
	getMapMarkers: vi.fn(),
	searchByLocation: vi.fn(),
	getExplore: vi.fn(),
	getMe: vi.fn(),
	getFolderContent: vi.fn(),
}))

vi.mock('../../src/services/api.js', () => apiMocks)
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	getCanonicalLocale: () => 'en',
}))
vi.mock('../../src/services/storage.js', () => ({
	appStorage: { getItem: () => null, setItem: vi.fn() },
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
}))
// Keep bucket loading deterministic: only the explicit month-selection request
// loads assets, rather than viewport measurements triggering background requests.
vi.mock('../../src/composables/useVirtualScroll.js', () => ({
	useVirtualScroll: () => ({
		scrollContainer: ref(null),
		scrollTop: ref(0),
		viewportHeight: ref(600),
		containerWidth: ref(0),
		viewportWidth: ref(1024),
		stickyOffset: ref(0),
		onScroll: vi.fn(),
		resetScroll: vi.fn(),
	}),
}))

import PhotoGrid from '../../src/components/PhotoGrid.vue'
import TimelineView from '../../src/components/TimelineView.vue'
import { useImmichStore } from '../../src/store/immich.js'

const ownAsset = { id: 'own', ownerId: 'me' }
const partnerAsset = { id: 'partner', ownerId: 'other' }
const bucket = { timeBucket: '2026-10-01T00:00:00.000Z', count: 2 }
let wrapper

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = null
})

describe('selection with partner assets', () => {
	it.each([null, 'me'])('selects owned and partner thumbnails with current user %s', async (currentUserId) => {
		const store = useImmichStore()
		store.currentUserId = currentUserId
		store.enterSelectionMode()
		wrapper = mount(PhotoGrid, { props: { assets: [ownAsset, partnerAsset], selectable: true } })

		expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(2)
		for (const item of wrapper.findAll('.photo-grid__item')) await item.trigger('click')

		expect([...store.selectedAssetIds]).toEqual(['own', 'partner'])
		expect(wrapper.emitted('click')).toBeUndefined()
		expect(wrapper.findAll('.photo-grid__item--selected')).toHaveLength(2)
		await wrapper.findAll('.photo-grid__item')[1].trigger('click')
		expect([...store.selectedAssetIds]).toEqual(['own'])
	})

	it('enters selection mode through a partner checkbox without double toggling', async () => {
		const store = useImmichStore()
		wrapper = mount(PhotoGrid, { props: { assets: [partnerAsset], selectable: true } })

		await wrapper.get('input').setValue(true)
		await wrapper.get('input').trigger('click')

		expect(store.isSelectionMode).toBe(true)
		expect([...store.selectedAssetIds]).toEqual(['partner'])
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('preserves lightbox clicks in non-selectable partner views', async () => {
		const store = useImmichStore()
		store.enterSelectionMode()
		wrapper = mount(PhotoGrid, { props: { assets: [partnerAsset], selectable: false } })

		expect(wrapper.find('input').exists()).toBe(false)
		await wrapper.get('.photo-grid__item').trigger('click')
		expect(store.selectedAssetIds.size).toBe(0)
		expect(wrapper.emitted('click')[0]).toEqual([partnerAsset, 0])
	})

	it.each([[ownAsset, partnerAsset], [partnerAsset]])('selects and deselects all assets in a month: %j', async (...assets) => {
		const store = useImmichStore()
		apiMocks.getTimeline.mockImplementation(({ timeBucket }) => Promise.resolve({
			data: timeBucket ? assets : [bucket],
		}))
		wrapper = mount(TimelineView)
		await flushPromises()

		await wrapper.get('.timeline-view__select-bucket').trigger('click')
		await flushPromises()

		expect(apiMocks.getTimeline).toHaveBeenCalledWith(
			{ timeBucket: bucket.timeBucket, withPartners: true }, expect.any(AbortSignal),
		)
		expect(store.isSelectionMode).toBe(true)
		expect([...store.selectedAssetIds]).toEqual(assets.map(asset => asset.id))

		await wrapper.get('.timeline-view__select-bucket').trigger('click')
		await flushPromises()
		expect(store.selectedAssetIds.size).toBe(0)
	})
})

describe('selection mutation permissions', () => {
	it('allows owned-only mutations but blocks partner and mixed selections', () => {
		const store = useImmichStore()
		store.currentUserId = 'me'
		store.timelineAssets[bucket.timeBucket] = [ownAsset, partnerAsset]
		expect(store.selectedAssetsCanEdit).toBe(false)
		store.toggleAssetSelection('own')
		expect(store.selectedAssetsCanEdit).toBe(true)
		store.toggleAssetSelection('partner')
		expect(store.selectedAssetsCanEdit).toBe(false)
		store.toggleAssetSelection('own')
		expect(store.selectedAssetsCanEdit).toBe(false)
	})

	it('blocks mutations while identity is unavailable and after cache eviction', async () => {
		const store = useImmichStore()
		store.timelineAssets[bucket.timeBucket] = [ownAsset]
		store.toggleAssetSelection('own')
		expect(store.selectedAssetsCanEdit).toBe(false)
		apiMocks.getMe.mockRejectedValueOnce(new Error('Missing user.read permission'))
		await store.fetchCurrentUser()
		expect(store.selectedAssetsCanEdit).toBe(false)
		expect([...store.selectedAssetIds]).toEqual(['own'])
		apiMocks.getMe.mockResolvedValueOnce({ data: { id: 'me' } })
		await store.fetchCurrentUser()
		expect(store.selectedAssetsCanEdit).toBe(true)
		store.unloadTimelineBucket(bucket.timeBucket)
		expect(store.selectedAssetsCanEdit).toBe(false)
		expect([...store.selectedAssetIds]).toEqual(['own'])
	})
})