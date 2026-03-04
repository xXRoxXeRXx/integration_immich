/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NC26-32 compatible file action using @nextcloud/files v3.
 * v3 uses positional arguments:
 *   enabled(nodes, view)
 *   exec(node, view, dir)
 *   execBatch(nodes, view, dir)
 */
import { registerFileAction, FileAction } from '@nextcloud/files-v3'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

const immichSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'

async function uploadFile(node) {
	if (!node.fileid) throw new Error('File ID missing')
	const url = generateUrl('/apps/integration_immich/api/v1/upload')
	await axios.post(url, { fileId: node.fileid })
}

registerFileAction(new FileAction({
	id: 'send-to-immich',
	displayName: () => t('integration_immich', 'Add to Immich'),
	iconSvgInline: () => immichSvg,

	// NC32 calls: enabled(nodes, view)
	enabled(nodes, view) {
		const ignoredViews = ['trashbin', 'public']
		if (view?.id && ignoredViews.includes(view.id)) return false

		return nodes.length > 0 && nodes.every(node => {
			const mime = node.mime || ''
			return mime.startsWith('image/') || mime.startsWith('video/')
		})
	},

	order: 90,

	// NC32 calls: exec(node, view, dir)
	async exec(node, view, dir) {
		try {
			await uploadFile(node)
			showSuccess(t('integration_immich', '"{name}" added to Immich', { name: node.basename }))
			return true
		} catch (e) {
			const errorMsg = e.response?.data?.error || e.message
			showError(t('integration_immich', 'Error uploading to Immich: {error}', { error: errorMsg }))
			return false
		}
	},

	// NC32 calls: execBatch(nodes, view, dir)
	async execBatch(nodes, view, dir) {
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
			showSuccess(t('integration_immich', '{count} files added to Immich', { count: successCount }))
		}
		if (failCount > 0) {
			showError(t('integration_immich', '{count} files could not be uploaded', { count: failCount }))
		}

		return results
	},
}))
