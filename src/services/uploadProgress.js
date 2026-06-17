/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp, defineComponent, h, ref } from 'vue'
import { NcProgressBar } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Mount a temporary progress overlay during batch upload.
 *
 * @param {number} total - Total number of files to process
 * @return {{ update: (completed: number) => void, close: () => void }}
 */
export function showBatchProgress(total) {
	const container = document.createElement('div')
	container.style.cssText = [
		'position:fixed', 'bottom:20px', 'left:50%', 'transform:translateX(-50%)',
		'z-index:100000', 'background:var(--color-main-background)',
		'border:1px solid var(--color-border)', 'border-radius:var(--border-radius-large)',
		'box-shadow:var(--filter-drop-shadow)', 'padding:12px 16px',
		'min-width:280px', 'max-width:400px',
	].join(';')
	document.body.appendChild(container)

	const completed = ref(0)

	const ProgressApp = defineComponent({
		setup() {
			return () => h('div', [
				h('p', {
					style: 'margin:0 0 8px;font-size:13px;color:var(--color-text-light)',
				}, t('integration_immich', 'Uploading to Immich ({done}/{total})', {
					done: completed.value,
					total,
				})),
				h(NcProgressBar, {
					value: total > 0 ? Math.round((completed.value / total) * 100) : 0,
					size: 'small',
				}),
			])
		},
	})

	const app = createApp(ProgressApp)
	app.mount(container)

	return {
		/**
		 * Update the progress counter.
		 *
		 * @param {number} n - Number of files completed so far
		 */
		update(n) {
			completed.value = n
		},
		/**
		 * Remove the progress overlay from the DOM.
		 */
		close() {
			app.unmount()
			if (document.body.contains(container)) {
				document.body.removeChild(container)
			}
		},
	}
}
