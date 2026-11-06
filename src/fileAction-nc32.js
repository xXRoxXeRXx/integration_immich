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
import { showBatchProgress } from './services/uploadProgress.js'

const immichSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'

// visibility: 'timeline', or 'locked' for straight into the Locked Folder (no PIN needed)
async function uploadFile(node, visibility) {
	if (!node.fileid) throw new Error('File ID missing')
	const url = generateUrl('/apps/integration_immich/api/v1/upload')
	await axios.post(url, { fileId: node.fileid, visibility })
}

function registerUpload({ id, displayName, visibility, order, added, addedBatch }) {
	registerFileAction(new FileAction({
		id,
		displayName,
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

		order,

		// NC32 calls: exec(node, view, dir)
		async exec(node, view, dir) {
			try {
				await uploadFile(node, visibility)
				showSuccess(added(node.basename))
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
			let completed = 0
			const progress = showBatchProgress(nodes.length)

			let index = 0
			async function runWorker() {
				while (index < nodes.length) {
					const i = index++
					try {
						await uploadFile(nodes[i], visibility)
						results[i] = true
					} catch {
						results[i] = false
					}
					completed++
					progress.update(completed)
				}
			}

			const workers = Array.from({ length: Math.min(CONCURRENCY, nodes.length) }, runWorker)
			await Promise.all(workers)
			progress.close()

			const successCount = results.filter(Boolean).length
			const failCount = results.length - successCount

			if (successCount > 0) {
				showSuccess(addedBatch(successCount))
			}
			if (failCount > 0) {
				showError(t('integration_immich', '{count} files could not be uploaded', { count: failCount }))
			}

			return results
		},
	}))
}

registerUpload({
	id: 'send-to-immich',
	displayName: () => t('integration_immich', 'Add to Immich'),
	visibility: 'timeline',
	order: 90,
	added: name => t('integration_immich', '"{name}" added to Immich', { name }),
	addedBatch: count => t('integration_immich', '{count} files added to Immich', { count }),
})

registerUpload({
	id: 'send-to-immich-locked',
	displayName: () => t('integration_immich', 'Add to Immich Locked Folder'),
	visibility: 'locked',
	order: 91,
	added: name => t('integration_immich', '"{name}" added to the Immich Locked Folder', { name }),
	addedBatch: count => t('integration_immich', '{count} files added to the Immich Locked Folder', { count }),
})
