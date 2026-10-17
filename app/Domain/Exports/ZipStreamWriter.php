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
 *
 * **ZIP64** (APPNOTE 4.5) is written where the classic fields do not suffice, so neither size nor
 * entry count limits an export:
 *
 * - an entry whose size is not declared, or is at least ZIP64_THRESHOLD, gets a ZIP64 extra field
 *   in its local and central headers (its sizes there are 0xFFFFFFFF);
 * - an entry whose local header starts at or beyond the threshold gets its offset in the central
 *   header's ZIP64 extra field;
 * - more than 65,534 entries, or a central directory at or beyond the threshold, add the ZIP64
 *   end-of-central-directory record and locator.
 *
 * Archives without any of these are plain zip 2.0 files, byte for byte as before. The threshold is
 * a little below 4 GiB so that a deflated entry that grows slightly is still covered; tests lower it
 * to exercise every ZIP64 structure with small data.
 */
final class ZipStreamWriter
{
    public const ZIP64_THRESHOLD = 0xF0000000;

    private const MAX32 = 0xFFFFFFFF;

    private const MAX16 = 0xFFFF;

    private const CHUNK = 1024 * 1024;

    /** @var resource */
    private $handle;

    /** @var list<array{name: string, method: int, crc: int, compressed: int, size: int, offset: int, zip64: bool}> */
    private array $entries = [];

    private bool $closed = false;

    private int $dosTime;

    private int $dosDate;

    public function __construct(string $path, DateTimeInterface $modified, private readonly int $zip64Threshold = self::ZIP64_THRESHOLD)
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
     * @param  int|null  $size  the stream's length when known; unknown sizes always get a ZIP64 extra field
     */
    public function addStream(string $name, $source, bool $deflate, ?int $size = null): void
    {
        if ($this->closed) {
            throw new LogicException('The archive is closed.');
        }

        $deflate = $deflate && function_exists('deflate_init');
        $method = $deflate ? 8 : 0;
        $zip64 = $size === null || $size >= $this->zip64Threshold;
        $offset = $this->position();

        // Local file header; CRC-32 and sizes are filled in once the data is written.
        $this->write(pack(
            'VvvvvvVVVvv',
            0x04034B50,
            $zip64 ? 45 : 20,
            0x0800,
            $method,
            $this->dosTime,
            $this->dosDate,
            0,
            $zip64 ? self::MAX32 : 0,
            $zip64 ? self::MAX32 : 0,
            strlen($name),
            $zip64 ? 20 : 0,
        ).$name.($zip64 ? pack('vvPP', 0x0001, 16, 0, 0) : ''));
        $dataStart = $this->position();

        $crc = hash_init('crc32b');
        $written = 0;
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
            $written += strlen($chunk);
            $this->write($deflater === null ? $chunk : $this->deflate($deflater, $chunk, ZLIB_NO_FLUSH));
        }

        if ($deflater !== null) {
            $this->write($this->deflate($deflater, '', ZLIB_FINISH));
        }

        $end = $this->position();
        $compressed = $end - $dataStart;

        if (! $zip64 && ($written >= self::MAX32 || $compressed >= self::MAX32)) {
            throw new RuntimeException('An export entry is larger than it was declared.');
        }

        $crcValue = unpack('N', hash_final($crc, true));
        $crcInt = is_array($crcValue) && is_int($crcValue[1] ?? null) ? $crcValue[1] : 0;

        // Patch the CRC-32 (offset 14) and the sizes: in the header, or in its ZIP64 extra field.
        $this->seek($offset + 14);
        $this->write(pack('V', $crcInt).($zip64 ? '' : pack('VV', $compressed, $written)));

        if ($zip64) {
            $this->seek($offset + 30 + strlen($name) + 4);
            $this->write(pack('PP', $written, $compressed));
        }

        $this->seek($end);

        $this->entries[] = ['name' => $name, 'method' => $method, 'crc' => $crcInt, 'compressed' => $compressed, 'size' => $written, 'offset' => $offset, 'zip64' => $zip64];
    }

    public function addFile(string $name, string $path, bool $deflate): void
    {
        $source = fopen($path, 'rb');
        $size = filesize($path);

        if ($source === false || $size === false) {
            throw new RuntimeException('Cannot read an export entry.');
        }

        try {
            $this->addStream($name, $source, $deflate, $size);
        } finally {
            fclose($source);
        }
    }

    /** Writes the central directory (and the ZIP64 end records where needed) and closes the archive. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $start = $this->position();

        foreach ($this->entries as $entry) {
            $extra = '';
            $size = $entry['size'];
            $compressed = $entry['compressed'];
            $offset = $entry['offset'];

            if ($entry['zip64']) {
                $extra .= pack('PP', $size, $compressed);
                $size = $compressed = self::MAX32;
            }

            if ($offset >= $this->zip64Threshold) {
                $extra .= pack('P', $offset);
                $offset = self::MAX32;
            }

            $needs64 = $extra !== '';
            $extra = $needs64 ? pack('vv', 0x0001, strlen($extra)).$extra : '';

            $this->write(pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                $needs64 ? 0x032D : 0x0314, // made by: Unix, zip 4.5 / 2.0
                $needs64 ? 45 : 20,
                0x0800,
                $entry['method'],
                $this->dosTime,
                $this->dosDate,
                $entry['crc'],
                $compressed,
                $size,
                strlen($entry['name']),
                strlen($extra),
                0,
                0,
                0,
                0100644 << 16, // a regular file, rw-r--r--
                $offset,
            ).$entry['name'].$extra);
        }

        $end = $this->position();
        $count = count($this->entries);
        $directorySize = $end - $start;

        if ($count >= self::MAX16 || $start >= $this->zip64Threshold || $directorySize >= $this->zip64Threshold) {
            // ZIP64 end of central directory record and its locator; the classic record then
            // points at them with all-ones values.
            $this->write(pack('VPvvVVPPPP', 0x06064B50, 44, 0x032D, 45, 0, 0, $count, $count, $directorySize, $start));
            $this->write(pack('VVPV', 0x07064B50, 0, $end, 1));
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, self::MAX16, self::MAX16, self::MAX32, self::MAX32, 0));
        } else {
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $directorySize, $start, 0));
        }

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
