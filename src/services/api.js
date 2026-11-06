/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/integration_immich/api/v1')

// Assets in the Locked Folder are fetched with its unlocked Immich session (locked=1).
export function isLockedAsset(asset) {
	return asset?.visibility === 'locked'
}

function lockedQuery(locked, separator = '?') {
	return locked ? `${separator}locked=1` : ''
}

export function getTimeline(params = {}, signal = null) {
	return axios.get(`${baseUrl}/timeline`, { params, signal, timeout: 65000 })
}

export function getPartners() {
	return axios.get(`${baseUrl}/partners`)
}

export function getAlbums(params = {}) {
	return axios.get(`${baseUrl}/albums`, { params })
}

export function getMe() {
	return axios.get(`${baseUrl}/me`)
}

export function getAlbum(id) {
	return axios.get(`${baseUrl}/albums/${id}/show`)
}

export function createAlbum(albumName, assetIds = []) {
	return axios.post(`${baseUrl}/albums/create`, { albumName, assetIds })
}

export function addAssetsToAlbum(albumId, assetIds) {
	return axios.post(`${baseUrl}/albums/${albumId}/assets/add`, { assetIds })
}

export function deleteAlbum(albumId) {
	return axios.post(`${baseUrl}/albums/${albumId}/delete`)
}

export function renameAlbum(albumId, albumName) {
	return axios.post(`${baseUrl}/albums/${albumId}/rename`, { albumName })
}

export function removeAssetsFromAlbum(albumId, assetIds) {
	return axios.post(`${baseUrl}/albums/${albumId}/assets/remove`, { assetIds })
}

export function getAssetInfo(assetId, locked = false) {
	return axios.get(`${baseUrl}/assets/${assetId}/info${lockedQuery(locked)}`)
}

export function updateAsset(assetId, data) {
	return axios.post(`${baseUrl}/assets/${assetId}/update`, data)
}

export function getThumbnailUrl(assetId, locked = false) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/thumbnail`) + lockedQuery(locked)
}

export function getPreviewUrl(assetId, locked = false) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/thumbnail`) + '?size=preview' + lockedQuery(locked, '&')
}

export function getOriginalUrl(assetId, locked = false) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/original`) + lockedQuery(locked)
}

export function getVideoUrl(assetId, locked = false) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/video`) + lockedQuery(locked)
}

export function getAlbumThumbnailUrl(albumId) {
	return generateUrl(`/apps/integration_immich/api/v1/albums/${albumId}/thumbnail`)
}

export function getPeople() {
	return axios.get(`${baseUrl}/people`)
}

export function getPersonAssets(personId) {
	return axios.get(`${baseUrl}/people/${personId}/assets`)
}

export function getPersonThumbnailUrl(personId) {
	return generateUrl(`/apps/integration_immich/api/v1/people/${personId}/thumbnail`)
}

export function getMapMarkers() {
	return axios.get(`${baseUrl}/map/markers`)
}

export function searchByLocation(field, value) {
	return axios.get(`${baseUrl}/search/location`, { params: { field, value } })
}

export function getExplore() {
	return axios.get(`${baseUrl}/explore`)
}

export function uploadFile(fileId) {
	return axios.post(`${baseUrl}/upload`, { fileId })
}

export function getFolderContent(path = '/') {
	return axios.get(`${baseUrl}/folders/content`, { params: { path } })
}

export function saveAssetsToNextcloud(assetIds, path, locked = false) {
	return axios.post(`${baseUrl}/assets/save${lockedQuery(locked)}`, { assetIds, path })
}

export function downloadAssets(assetIds, locked = false) {
	return axios.post(`${baseUrl}/download${lockedQuery(locked)}`, { assetIds }, { responseType: 'blob' })
}

// visibility: 'timeline' | 'archive' | 'locked' (into the Locked Folder needs no PIN, out of it
// the unlocked session: locked = true)
export function setAssetsVisibility(assetIds, visibility, locked = false) {
	return axios.post(`${baseUrl}/assets/visibility${lockedQuery(locked)}`, { assetIds, visibility })
}

export function deleteAssets(assetIds, locked = false) {
	return axios.post(`${baseUrl}/assets/delete${lockedQuery(locked)}`, { assetIds })
}

// ---- Locked Folder: sign in to Immich, unlock with the PIN, lock again ----

export function getLockedStatus() {
	return axios.get(`${baseUrl}/locked/status`)
}

export function lockedLogin(email, password) {
	return axios.post(`${baseUrl}/locked/login`, { email, password })
}

export function lockedOAuthStart() {
	return axios.post(`${baseUrl}/locked/oauth`)
}

export function lockedUnlock(pinCode) {
	return axios.post(`${baseUrl}/locked/unlock`, { pinCode })
}

export function lockedLock() {
	return axios.post(`${baseUrl}/locked/lock`)
}

export function lockedSignOut() {
	return axios.post(`${baseUrl}/locked/signout`)
}

export function getConfig() {
	return axios.get(`${baseUrl}/config`)
}

export function setConfig(config) {
	return axios.put(`${baseUrl}/config`, config)
}
