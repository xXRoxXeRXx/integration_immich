/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createRouter, createWebHashHistory } from 'vue-router'
import TimelineView from '../components/TimelineView.vue'
import AlbumsView from '../components/AlbumsView.vue'
import AlbumDetailView from '../components/AlbumDetailView.vue'
import PeopleView from '../components/PeopleView.vue'
import PersonDetailView from '../components/PersonDetailView.vue'
import MapView from '../components/MapView.vue'
import ExploreView from '../components/ExploreView.vue'
import PlaceDetailView from '../components/PlaceDetailView.vue'

const routes = [
	{ path: '/', name: 'timeline', component: TimelineView },
	{ path: '/photos', name: 'photos', component: TimelineView, props: { assetType: 'IMAGE' } },
	{ path: '/videos', name: 'videos', component: TimelineView, props: { assetType: 'VIDEO' } },
	{ path: '/albums', name: 'albums', component: AlbumsView },
	{ path: '/albums/:id', name: 'album-detail', component: AlbumDetailView, props: true },
	{ path: '/people', name: 'people', component: PeopleView },
	{ path: '/people/:id', name: 'person-detail', component: PersonDetailView, props: true },
	{ path: '/map', name: 'map', component: MapView },
	{ path: '/explore', name: 'explore', component: ExploreView },
	{ path: '/explore/:field/:value', name: 'place-detail', component: PlaceDetailView, props: true },
]

const router = createRouter({
	history: createWebHashHistory(),
	routes,
})

export default router
