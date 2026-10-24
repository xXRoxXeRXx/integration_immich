/** @vitest-environment jsdom */
import { defineComponent, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
	getTimeline: vi.fn(),
	getThumbnailUrl: vi.fn(() => '/thumbnail'),
	loggerError: vi.fn(),
}))

vi.mock('../../src/services/api.js', () => ({
	getTimeline: apiMocks.getTimeline,
	getThumbnailUrl: apiMocks.getThumbnailUrl,
}))
vi.mock('../../src/services/logger.js', () => ({ logger: { error: apiMocks.loggerError } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcModal: { template: '<div><slot /></div>' },
	NcButton: { template: '<button><slot /><slot name="icon" /></button>' },
	NcLoadingIcon: { template: '<span />' },
}))

import AssetPickerModal from '../../src/components/AssetPickerModal.vue'

function deferred() {
	let resolve
	const promise = new Promise(res => {
		resolve = res
	})
	return { promise, resolve }
}

class ResizeObserverMock {
	constructor(callback) {
		this.callback = callback
	}

	observe(target) {
		this.callback([{ target, contentRect: { width: 420, height: 600 } }])
	}

	disconnect() {}
}

const Stub = defineComponent({ template: '<div><slot /><slot name="icon" /></div>' })

let bucketsResponse
let bucketRequests
let wrapper

beforeEach(() => {
	bucketsResponse = deferred()
	bucketRequests = []
	apiMocks.getTimeline.mockReset()
	apiMocks.loggerError.mockReset()
	apiMocks.getTimeline.mockImplementation((params, signal) => {
		if (!params.timeBucket) return bucketsResponse.promise
		const request = deferred()
		bucketRequests.push({ key: params.timeBucket, signal, ...request })
		return request.promise
	})
	vi.stubGlobal('ResizeObserver', ResizeObserverMock)
})

afterEach(() => {
	wrapper?.unmount()
	wrapper = null
	vi.unstubAllGlobals()
})

describe('AssetPickerModal request lifecycle', () => {
	it('aborts active bucket requests and never starts queued work after close', async () => {
		wrapper = mount(AssetPickerModal, {
			props: { albumName: 'Test album' },
			global: {
				stubs: {
					NcModal: { template: '<div><slot /></div>' },
					NcButton: Stub,
					NcLoadingIcon: Stub,
				},
			},
		})

		bucketsResponse.resolve({
			data: Array.from({ length: 12 }, (_, index) => ({
				timeBucket: `bucket-${index}`,
				count: 1,
			})),
		})
		await flushPromises()
		await nextTick()
		await vi.waitFor(() => expect(bucketRequests).toHaveLength(2))

		const started = bucketRequests.map(request => request.key)
		const pendingPromises = bucketRequests.map(request => request.promise)
		wrapper.unmount()
		wrapper = null

		expect(bucketRequests.map(request => request.signal.aborted)).toEqual([true, true])
		expect(started).toEqual(['bucket-0', 'bucket-1'])
		expect(bucketRequests).toHaveLength(2)

		for (const request of bucketRequests) request.resolve({ data: [] })
		await Promise.all(pendingPromises)
	})
})
