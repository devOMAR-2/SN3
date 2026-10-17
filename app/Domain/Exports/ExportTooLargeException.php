<?php

namespace App\Domain\Exports;

use RuntimeException;

/**
 * The export would exceed what a zip archive without ZIP64 holds (4 GiB, 65,535 entries;
 * ZipStreamWriter). BuildTeamExport refuses the export (`too_large`) instead of retrying it.
 */
final class ExportTooLargeException extends RuntimeException {}
