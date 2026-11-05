<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

/** Immich answered with an HTTP error; the status tells a wrong PIN or a locked session apart. */
class ImmichHttpException extends \RuntimeException {
    public function __construct(
        private int $status,
        string $message = '',
    ) {
        parent::__construct('Immich API error: HTTP ' . $status . ($message !== '' ? ' (' . $message . ')' : ''));
    }

    public function getStatus(): int {
        return $this->status;
    }
}
