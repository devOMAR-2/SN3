<?php

namespace App\Domain\Exports;

/**
 * An export section whose records refer to stored files that go into the archive too (CORE-008,
 * ADR-027 §7: attachments under `files/`). TeamExportWriter calls files() after records(), so a
 * section can list exactly the files its records announced.
 *
 * Entry names must come from the format and IDs (`files/<id>`), never from data such as a file
 * name, so nothing in an export can traverse paths or collide.
 */
interface TeamExportFiles
{
    /**
     * Archive entry name => an open, readable stream of the file's content (the writer closes it)
     * and its size in bytes when known (ZipStreamWriter decides on ZIP64 fields from it).
     *
     * @return iterable<string, array{stream: resource, size: int|null}>
     */
    public function files(TeamExportContext $context): iterable;
}
