<?php

namespace App\Domain\Exports;

use DateTimeInterface;
use DeflateContext;
use LogicException;
use RuntimeException;

/**
 * A minimal streaming zip writer for team exports (ADR-024 §2 and §8; CORE-008, ADR-027 §7).
 *
 * ADR-024 built the archive with PharData, which rewrites the whole archive to compress it. Once
 * attachments are in the archive that would copy every file twice, so entries are now streamed into
 * the archive once, chunk by chunk, with flat memory: data files deflated, attachment files stored
 * as they are (they are usually compressed already). Only PHP's bundled zlib is needed for
 * deflating; without it entries are stored.
 *
 * The writer seeks back to fill in each entry's CRC-32 and sizes, so it writes to a local file.
 * Names are UTF-8 (general purpose flag bit 11) and come from the export format, never from data.
 * ZIP64 is not written: an archive that would exceed 4 GiB or 65,535 entries is refused with
 * ExportTooLargeException before it becomes invalid.
 */
final class ZipStreamWriter
{
    private const LIMIT = 0xFFFFFFFF;

    private const MAX_ENTRIES = 0xFFFF;

    private const CHUNK = 1024 * 1024;

    /** @var resource */
    private $handle;

    /** @var list<array{name: string, method: int, crc: int, compressed: int, size: int, offset: int}> */
    private array $entries = [];

    private bool $closed = false;

    private int $dosTime;

    private int $dosDate;

    public function __construct(string $path, DateTimeInterface $modified)
    {
        $handle = fopen($path, 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Cannot write the export archive.');
        }

        $this->handle = $handle;
        $year = max(1980, (int) $modified->format('Y'));
        $this->dosTime = ((int) $modified->format('G') << 11) | ((int) $modified->format('i') << 5) | intdiv((int) $modified->format('s'), 2);
        $this->dosDate = (($year - 1980) << 9) | ((int) $modified->format('n') << 5) | (int) $modified->format('j');
    }

    /**
     * Adds an entry from a stream (read to its end, not closed).
     *
     * @param  resource  $source
     */
    public function addStream(string $name, $source, bool $deflate): void
    {
        if ($this->closed) {
            throw new LogicException('The archive is closed.');
        }

        if (count($this->entries) >= self::MAX_ENTRIES) {
            throw new ExportTooLargeException('The export has too many entries for a zip archive.');
        }

        $deflate = $deflate && function_exists('deflate_init');
        $method = $deflate ? 8 : 0;
        $offset = $this->position();

        // Local file header; CRC-32 and sizes are filled in once the data is written.
        $this->write(pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, $method, $this->dosTime, $this->dosDate, 0, 0, 0, strlen($name), 0).$name);
        $dataStart = $this->position();

        $crc = hash_init('crc32b');
        $size = 0;
        $deflater = null;

        if ($deflate) {
            $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);

            if ($context === false) {
                throw new RuntimeException('Cannot compress the export archive.');
            }

            $deflater = $context;
        }

        while (! feof($source)) {
            $chunk = fread($source, self::CHUNK);

            if ($chunk === false) {
                throw new RuntimeException('Cannot read an export entry.');
            }

            if ($chunk === '') {
                continue;
            }

            hash_update($crc, $chunk);
            $size += strlen($chunk);
            $this->write($deflater === null ? $chunk : $this->deflate($deflater, $chunk, ZLIB_NO_FLUSH));
            $this->assertWithinLimit($size);
        }

        if ($deflater !== null) {
            $this->write($this->deflate($deflater, '', ZLIB_FINISH));
        }

        $end = $this->position();
        $compressed = $end - $dataStart;
        $this->assertWithinLimit($end);

        $crcValue = unpack('N', hash_final($crc, true));
        $crcInt = is_array($crcValue) && is_int($crcValue[1] ?? null) ? $crcValue[1] : 0;

        // Patch the CRC-32 and both sizes into the local header (offset 14).
        $this->seek($offset + 14);
        $this->write(pack('VVV', $crcInt, $compressed, $size));
        $this->seek($end);

        $this->entries[] = ['name' => $name, 'method' => $method, 'crc' => $crcInt, 'compressed' => $compressed, 'size' => $size, 'offset' => $offset];
    }

    public function addFile(string $name, string $path, bool $deflate): void
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new RuntimeException('Cannot read an export entry.');
        }

        try {
            $this->addStream($name, $source, $deflate);
        } finally {
            fclose($source);
        }
    }

    /** Writes the central directory and closes the archive. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $start = $this->position();

        foreach ($this->entries as $entry) {
            $this->write(pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                0x0314, // made by: Unix, zip 2.0
                20,
                0x0800,
                $entry['method'],
                $this->dosTime,
                $this->dosDate,
                $entry['crc'],
                $entry['compressed'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0100644 << 16, // a regular file, rw-r--r--
                $entry['offset'],
            ).$entry['name']);
        }

        $end = $this->position();
        $this->assertWithinLimit($end);

        $count = count($this->entries);
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $end - $start, $start, 0));

        fclose($this->handle);
        $this->closed = true;
    }

    private function deflate(DeflateContext $deflater, string $data, int $mode): string
    {
        $out = deflate_add($deflater, $data, $mode);

        if ($out === false) {
            throw new RuntimeException('Cannot compress the export archive.');
        }

        return $out;
    }

    private function assertWithinLimit(int $bytes): void
    {
        if ($bytes >= self::LIMIT) {
            throw new ExportTooLargeException('The export is too large for a zip archive.');
        }
    }

    private function write(string $data): void
    {
        if ($data !== '' && fwrite($this->handle, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write the export archive.');
        }
    }

    private function position(): int
    {
        $position = ftell($this->handle);

        if ($position === false) {
            throw new RuntimeException('Cannot write the export archive.');
        }

        return $position;
    }

    private function seek(int $offset): void
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw new RuntimeException('Cannot write the export archive.');
        }
    }
}
