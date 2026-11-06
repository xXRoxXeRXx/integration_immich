/** @vitest-environment jsdom */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
	getLockedStatus: vi.fn(),
	lockedLogin: vi.fn(),
	lockedOAuthStart: vi.fn(),
	lockedUnlock: vi.fn(),
	lockedLock: vi.fn(),
	lockedSignOut: vi.fn(),
}))

vi.mock('../../src/services/api.js', () => apiMocks)
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: path => `/index.php${path}` }))
vi.mock('../../src/services/storage.js', () => ({ appStorage: { getItem: () => null, setItem: vi.fn(), removeItem: vi.fn() } }))
vi.mock('@nextcloud/vue', () => {
	const Stub = { template: '<div><slot /><slot name="icon" /><slot name="action" /></div>' }
	return {
		NcButton: Stub,
		NcDialog: { props: ['name'], template: '<div class="dialog" :data-name="name"><slot /></div>' },
		NcEmptyContent: Stub,
		NcLoadingIcon: Stub,
		NcNoteCard: Stub,
		NcPasswordField: Stub,
		NcTextField: Stub,
	}
})

import { useImmichStore } from '../../src/store/immich.js'
import LockedFolderView from '../../src/components/LockedFolderView.vue'

const unlocked = expiresIn => ({ data: { signedIn: true, pinCode: true, unlocked: true, expiresIn } })
const locked = { data: { signedIn: true, pinCode: true, unlocked: false, expiresIn: null } }
const lockedAsset = { id: 'l1', visibility: 'locked' }

let store
let wrapper

async function mountView() {
	wrapper = mount(LockedFolderView, { global: { stubs: { TimelineView: true } } })
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	store = useImmichStore()
	for (const mock of Object.values(apiMocks)) mock.mockReset()
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = null
	vi.useRealTimers()
})

describe('Locked Folder view', () => {
	it('closes a lightbox on its photos when it is left', async () => {
		apiMocks.getLockedStatus.mockResolvedValue(unlocked(900))
		await mountView()
		store.visibilityAssets.locked = { '2026-01-01': [lockedAsset] }
		store.openLightbox([lockedAsset])

		wrapper.unmount()
		wrapper = null

		expect(store.lightbox.visible).toBe(false)
		expect(store.visibilityAssets.locked).toEqual({})
	})

	it('hides the photos and asks for the PIN when a photo or video fails on a lapsed PIN', async () => {
		apiMocks.getLockedStatus.mockResolvedValueOnce(unlocked(900)).mockResolvedValueOnce(locked)
		await mountView()
		store.openLightbox([lockedAsset])

		store.checkLockedFolder()
		await flushPromises()

		expect(apiMocks.getLockedStatus).toHaveBeenCalledTimes(2)
		expect(store.lightbox.visible).toBe(false)
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Enter your PIN')
	})

	it('keeps the lightbox when the folder is still open after a failed photo', async () => {
		apiMocks.getLockedStatus.mockResolvedValue(unlocked(900))
		await mountView()
		store.openLightbox([lockedAsset])

		store.checkLockedFolder()
		await flushPromises()

		expect(store.lightbox.visible).toBe(true)
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('asks once just after the expiry and hides the photos once locked', async () => {
		vi.useFakeTimers()
		apiMocks.getLockedStatus.mockResolvedValueOnce(unlocked(60)).mockResolvedValueOnce(locked)
		await mountView()
		store.openLightbox([lockedAsset])

		await vi.advanceTimersByTimeAsync(60 * 1000)
		expect(apiMocks.getLockedStatus).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(5000)

		expect(apiMocks.getLockedStatus).toHaveBeenCalledTimes(2)
		expect(store.lightbox.visible).toBe(false)
	})

	it('asks only for the PIN when signed in with the account in the settings', async () => {
		apiMocks.getLockedStatus.mockResolvedValue({ data: { ...locked.data, account: true } })
		await mountView()

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Enter your PIN')
		expect(wrapper.text()).not.toContain('Sign out of Immich')
	})

	it('sends to the settings when the account was signed out in Immich', async () => {
		apiMocks.getLockedStatus.mockResolvedValue({ data: { signedIn: false, account: true, accountSignedOut: true } })
		await mountView()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(wrapper.text()).toContain('Open the settings')
	})
})
