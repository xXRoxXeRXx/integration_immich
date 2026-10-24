/** @vitest-environment jsdom */
import { defineComponent, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useVirtualScroll } from '../../src/composables/useVirtualScroll.js'

let wrapper
let virtualScroll
let resizeObserver
let onLargeScrollJump
let nextFrameId
let animationFrames
let originalInnerWidth

class ResizeObserverMock {
	constructor(callback) {
		this.callback = callback
		resizeObserver = this
	}

	observe = vi.fn()
	disconnect = vi.fn()

	resize(target, width, height) {
		this.callback([{ target, contentRect: { width, height } }])
	}
}

const ScrollHarness = defineComponent({
	setup() {
		onLargeScrollJump = vi.fn()
		const stickyElement = ref(null)
		virtualScroll = useVirtualScroll({ onLargeScrollJump, stickyElement })
		return { ...virtualScroll, stickyElement }
	},
	template: '<div ref="scrollContainer" @scroll="onScroll"><div ref="stickyElement" class="sticky"></div></div>',
})

function runNextFrame() {
	const [id, callback] = animationFrames.entries().next().value
	animationFrames.delete(id)
	callback(0)
}

beforeEach(() => {
	originalInnerWidth = window.innerWidth
	resizeObserver = null
	nextFrameId = 0
	animationFrames = new Map()
	vi.stubGlobal('ResizeObserver', ResizeObserverMock)
	vi.stubGlobal('requestAnimationFrame', vi.fn(callback => {
		const id = ++nextFrameId
		animationFrames.set(id, callback)
		return id
	}))
	vi.stubGlobal('cancelAnimationFrame', vi.fn(id => animationFrames.delete(id)))
	wrapper = mount(ScrollHarness, { attachTo: document.body })
})

afterEach(() => {
	wrapper?.unmount()
	Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalInnerWidth })
	vi.unstubAllGlobals()
})

describe('useVirtualScroll', () => {
	it('observes container dimensions and coalesces scroll updates into one frame', async () => {
		const element = wrapper.element
		Object.defineProperties(element, {
			clientWidth: { configurable: true, value: 420 },
			clientHeight: { configurable: true, value: 100 },
		})
		await nextTick()

		expect(resizeObserver.observe).toHaveBeenCalledWith(element)
		resizeObserver.resize(element, 390, 120)
		expect(virtualScroll.containerWidth.value).toBe(390)
		expect(virtualScroll.viewportHeight.value).toBe(120)

		element.scrollTop = 500
		await wrapper.trigger('scroll')
		await wrapper.trigger('scroll')
		expect(animationFrames.size).toBe(1)
		runNextFrame()
		expect(virtualScroll.scrollTop.value).toBe(500)
		expect(onLargeScrollJump).toHaveBeenCalledWith(500, 0)
	})

	it('tracks viewport breakpoints and sticky bar height without a container resize', async () => {
		const element = wrapper.element
		const sticky = wrapper.find('.sticky').element
		Object.defineProperties(element, {
			clientWidth: { configurable: true, value: 420 },
			clientHeight: { configurable: true, value: 300 },
		})
		Object.defineProperty(sticky, 'offsetHeight', { configurable: true, value: 42 })
		await nextTick()
		resizeObserver.resize(element, 420, 300)

		Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 })
		window.dispatchEvent(new Event('resize'))
		resizeObserver.resize(sticky, 390, 42)
		expect(virtualScroll.viewportWidth.value).toBe(390)
		expect(virtualScroll.containerWidth.value).toBe(420)
		expect(virtualScroll.stickyOffset.value).toBe(42)
	})

	it('resets scroll state and cancels pending frame/observer on unmount', async () => {
		const element = wrapper.element
		Object.defineProperties(element, {
			clientWidth: { configurable: true, value: 420 },
			clientHeight: { configurable: true, value: 100 },
		})
		await nextTick()
		element.scrollTop = 500
		await wrapper.trigger('scroll')
		virtualScroll.resetScroll()
		expect(element.scrollTop).toBe(0)
		expect(virtualScroll.scrollTop.value).toBe(0)

		await wrapper.trigger('scroll')
		const pendingId = animationFrames.keys().next().value
		wrapper.unmount()
		wrapper = null
		expect(cancelAnimationFrame).toHaveBeenCalledWith(pendingId)
		expect(resizeObserver.disconnect).toHaveBeenCalled()
	})
})
