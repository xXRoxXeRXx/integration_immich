import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/integration_immich/api/v1')

export function getTimeline(params = {}) {
	return axios.get(`${baseUrl}/timeline`, { params })
}

export function getAlbums() {
	return axios.get(`${baseUrl}/albums`)
}

export function getAlbum(id) {
	return axios.get(`${baseUrl}/albums/${id}`)
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

export function getConfig() {
	return axios.get(`${baseUrl}/config`)
}

export function setConfig(config) {
	return axios.put(`${baseUrl}/config`, config)
}
