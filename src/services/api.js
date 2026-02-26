/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/integration_immich/api/v1')

export function getTimeline(params = {}) {
	return axios.get(`${baseUrl}/timeline`, { params })
}

export function getAlbums(params = {}) {
	return axios.get(`${baseUrl}/albums`, { params })
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

export function getAssetInfo(assetId) {
	return axios.get(`${baseUrl}/assets/${assetId}/info`)
}

export function updateAsset(assetId, data) {
	return axios.post(`${baseUrl}/assets/${assetId}/update`, data)
}

export function getThumbnailUrl(assetId) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/thumbnail`)
}

export function getPreviewUrl(assetId) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/thumbnail`) + '?size=preview'
}

export function getOriginalUrl(assetId) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/original`)
}

export function getVideoUrl(assetId) {
	return generateUrl(`/apps/integration_immich/api/v1/assets/${assetId}/video`)
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

export function getExplore() {
	return axios.get(`${baseUrl}/explore`)
}

export function uploadFile(fileId) {
	return axios.post(`${baseUrl}/upload`, { fileId })
}

export function saveAssetsToNextcloud(assetIds, path) {
	return axios.post(`${baseUrl}/assets/save`, { assetIds, path })
}

export function downloadAssets(assetIds) {
	return axios.post(`${baseUrl}/download`, { assetIds }, { responseType: 'blob' })
}

export function getConfig() {
	return axios.get(`${baseUrl}/config`)
}

export function setConfig(config) {
	return axios.put(`${baseUrl}/config`, config)
}
