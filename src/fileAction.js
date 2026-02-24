import { registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

const immichSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'

async function uploadFile(node) {
	const url = generateUrl('/apps/integration_immich/api/v1/upload')
	await axios.post(url, { fileId: node.fileid })
}

registerFileAction({
	id: 'send-to-immich',
	displayName: () => t('integration_immich', 'Zu Immich hinzufügen'),
	iconSvgInline: () => immichSvg,

	enabled({ nodes, view }) {
		const ignoredViews = ['trashbin', 'public']
		if (view?.id && ignoredViews.includes(view.id)) return false

		return nodes.length > 0 && nodes.every(node => {
			const mime = node.mime || ''
			return mime.startsWith('image/') || mime.startsWith('video/')
		})
	},

	order: 90,

	async exec({ nodes }) {
		const node = nodes[0]
		try {
			await uploadFile(node)
			showSuccess(t('integration_immich', '"{name}" wurde zu Immich hinzugefügt', { name: node.basename }))
			return true
		} catch (e) {
			const errorMsg = e.response?.data?.error || e.message
			showError(t('integration_immich', 'Fehler beim Hochladen zu Immich: {error}', { error: errorMsg }))
			return false
		}
	},

	async execBatch({ nodes }) {
		const CONCURRENCY = 3
		const results = new Array(nodes.length).fill(null)

		let index = 0
		async function runWorker() {
			while (index < nodes.length) {
				const i = index++
				try {
					await uploadFile(nodes[i])
					results[i] = true
				} catch {
					results[i] = false
				}
			}
		}

		const workers = Array.from({ length: Math.min(CONCURRENCY, nodes.length) }, runWorker)
		await Promise.all(workers)

		const successCount = results.filter(Boolean).length
		const failCount = results.length - successCount

		if (successCount > 0) {
			showSuccess(t('integration_immich', '{count} Dateien zu Immich hinzugefügt', { count: successCount }))
		}
		if (failCount > 0) {
			showError(t('integration_immich', '{count} Dateien konnten nicht hochgeladen werden', { count: failCount }))
		}

		return results
	},
})
