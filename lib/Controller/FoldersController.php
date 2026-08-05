<?php

// SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class FoldersController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function errorResponse(string $context, \Exception $e): JSONResponse {
        $this->logger->error('Immich ' . $context . ' failed: ' . $e->getMessage(), [
            'app' => Application::APP_ID,
            'exception' => $e,
        ]);
        return new JSONResponse(
            ['error' => 'An internal error occurred'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function content(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        $path = (string) $this->request->getParam('path', '/');

        // Reject ".." only when it appears as its own path segment
        // (e.g. /foo/../bar), so legitimate names like "foo..bar" remain valid.
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return new JSONResponse(['error' => 'Invalid path'], Http::STATUS_BAD_REQUEST);
        }

        // Normalise: must start with /, no trailing slash (except root)
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        try {
            // Immich v2+: /view/folder/unique-paths returns all folder paths that
            // contain at least one asset (e.g. ["/DCIM/Camera", "/DCIM/WhatsApp"]).
            $allPaths = $this->immichService->getUniqueFolderPaths();

            // Compute the deepest common ancestor of all paths.
            // This lets the frontend skip straight to the real content root
            // (e.g. /usr/src/app/upload/library/username) instead of /
            $basePath = $this->computeBasePath($allPaths);

            // When the caller is still at virtual root (/), jump to the real base.
            if ($path === '/') {
                $path = $basePath;
            }

            $prefix  = $path === '/' ? '' : $path;
            $seenDirs = [];
            $subdirs  = [];

            foreach ($allPaths as $p) {
                if (!is_string($p)) {
                    continue;
                }
                if ($prefix === '') {
                    $relative = ltrim($p, '/');
                } else {
                    if (!str_starts_with($p, $prefix . '/')) {
                        continue;
                    }
                    $relative = substr($p, strlen($prefix) + 1);
                }
                $parts   = explode('/', $relative, 2);
                $dirName = $parts[0] ?? '';
                if ($dirName !== '' && !isset($seenDirs[$dirName])) {
                    $seenDirs[$dirName] = true;
                    $subdirs[] = ['name' => $dirName];
                }
            }

            // Sort subdirectories alphabetically
            usort($subdirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            // Assets directly inside the requested path
            $assets = $this->immichService->getFolderAssets($path);

            return new JSONResponse([
                'folders'     => $subdirs,
                'assets'      => $assets,
                'basePath'    => $basePath,
                'currentPath' => $path,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('folder content', $e);
        }
    }

    /**
     * Compute the deepest common ancestor of all given paths.
     *
     * Example:
     *   ["/usr/src/app/upload/library/user1/DCIM/Camera",
     *    "/usr/src/app/upload/library/user1/Photos"]
     *   → "/usr/src/app/upload/library/user1"
     */
    private function computeBasePath(array $paths): string {
        $paths = array_values(array_filter($paths, fn($p) => is_string($p) && $p !== ''));
        if (empty($paths)) {
            return '/';
        }

        // Split every path into non-empty segments
        $segmentArrays = array_map(function (string $p): array {
            return array_values(array_filter(explode('/', $p), fn($s) => $s !== ''));
        }, $paths);

        $reference  = $segmentArrays[0];
        $commonLen  = count($reference);

        foreach ($segmentArrays as $segs) {
            $commonLen = min($commonLen, count($segs));
            for ($i = 0; $i < $commonLen; $i++) {
                if ($reference[$i] !== $segs[$i]) {
                    $commonLen = $i;
                    break;
                }
            }
        }

        if ($commonLen === 0) {
            return '/';
        }

        return '/' . implode('/', array_slice($reference, 0, $commonLen));
    }
}
