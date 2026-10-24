import { ref, onBeforeUnmount, watch } from 'vue'

/**
 * Shared scroll/resize lifecycle for bucket-based virtual views.
 * Bucket geometry and request policy remain caller-owned.
 */
export function useVirtualScroll({ onLargeScrollJump = null, stickyElement = null } = {}) {
	const scrollContainer = ref(null)
	const scrollTop = ref(0)
	const viewportHeight = ref(800)
	const containerWidth = ref(0)
	const viewportWidth = ref(typeof window === 'undefined' ? 0 : window.innerWidth)
	const stickyOffset = ref(0)

	let scrollRaf = null
	let lastScrollTop = 0
	let resizeObserver = null

	function onScroll() {
		if (scrollRaf !== null) return
		scrollRaf = requestAnimationFrame(() => {
			const element = scrollContainer.value
			if (element) {
				const nextScrollTop = element.scrollTop
				if (Math.abs(nextScrollTop - lastScrollTop) > viewportHeight.value * 2) {
					onLargeScrollJump?.(nextScrollTop, lastScrollTop)
				}
				lastScrollTop = nextScrollTop
				scrollTop.value = nextScrollTop
				viewportHeight.value = element.clientHeight
			}
			scrollRaf = null
		})
	}

	function resetScroll() {
		lastScrollTop = 0
		scrollTop.value = 0
		if (scrollContainer.value) {
			scrollContainer.value.scrollTop = 0
			viewportHeight.value = scrollContainer.value.clientHeight
		}
	}

	function measureStickyOffset(element = stickyElement?.value) {
		stickyOffset.value = element?.offsetHeight ?? 0
	}

	function onWindowResize() {
		viewportWidth.value = window.innerWidth
		measureStickyOffset()
	}

	if (typeof window !== 'undefined') {
		window.addEventListener('resize', onWindowResize)
	}

	watch(() => [scrollContainer.value, stickyElement?.value ?? null], ([element, sticky]) => {
		resizeObserver?.disconnect()
		resizeObserver = null

		if (element) {
			containerWidth.value = element.clientWidth
			viewportHeight.value = element.clientHeight
		}
		measureStickyOffset(sticky)
		if (typeof ResizeObserver === 'undefined') return

		resizeObserver = new ResizeObserver(entries => {
			for (const entry of entries) {
				if (entry.target === element) {
					containerWidth.value = entry.contentRect.width
					viewportHeight.value = entry.contentRect.height
				}
				if (entry.target === sticky) measureStickyOffset(sticky)
			}
		})
		if (element) resizeObserver.observe(element)
		if (sticky) resizeObserver.observe(sticky)
	}, { flush: 'post' })

	onBeforeUnmount(() => {
		resizeObserver?.disconnect()
		resizeObserver = null
		if (typeof window !== 'undefined') {
			window.removeEventListener('resize', onWindowResize)
		}
		if (scrollRaf !== null) {
			cancelAnimationFrame(scrollRaf)
			scrollRaf = null
		}
	})

	return {
		scrollContainer,
		scrollTop,
		viewportHeight,
		containerWidth,
		viewportWidth,
		stickyOffset,
		stickyElement,
		onScroll,
		resetScroll,
	}
}
