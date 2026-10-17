<?php

namespace App\Domain\Exports;

use Illuminate\Contracts\Container\Container;
use LogicException;
use RuntimeException;

/**
 * Writes one team export archive (ADR-024 §2) into a working directory:
 *
 *     manifest.json        format, version, IDs, generation time and, per section, file, record count, SHA-256
 *     data/<section>.json  a JSON array, one record per line, for each TeamExportFormat::SECTIONS entry
 *     files/<id>           stored files of sections that have them (attachments, CORE-008, ADR-027 §7)
 *
 * Sections are streamed record by record to their file, so memory stays flat. The zip is streamed
 * by ZipStreamWriter (PHP has no zip extension in the image; ADR-024 used PharData, which rewrote
 * the whole archive to compress it): data entries are deflated, stored files are copied once, as
 * they are. Entry names are fixed by the format and IDs, never taken from data.
 */
final class TeamExportWriter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly Container $container) {}

    /**
     * Builds the archive and returns its path, inside `$workDir`.
     */
    public function build(TeamExportContext $context, string $workDir): string
    {
        $this->mkdir($workDir.'/data');

        $sections = [];
        $withFiles = [];

        foreach (TeamExportFormat::SECTIONS as $class) {
            $section = $this->container->make($class);
            $name = $section->name();

            if ($section instanceof TeamExportFiles) {
                $withFiles[] = $section;
            }

            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || isset($sections[$name])) {
                throw new LogicException("Invalid or duplicate export section name [{$name}].");
            }

            $file = "data/{$name}.json";
            $records = $this->writeJsonArray($workDir.'/'.$file, $section->records($context));

            $sections[$name] = [
                'name' => $name,
                'file' => $file,
                'records' => $records,
                'sha256' => (string) hash_file('sha256', $workDir.'/'.$file),
            ];
        }

        $manifest = [
            'format' => TeamExportFormat::NAME,
            'format_version' => TeamExportFormat::VERSION,
            'export_id' => $context->export->id,
            'team_id' => $context->team->id,
            'requested_by' => $context->actor->id,
            'generated_at' => TeamExportContext::timestamp(now()),
            'sections' => array_values($sections),
        ];

        $this->put($workDir.'/manifest.json', json_encode($manifest, self::JSON_FLAGS | JSON_PRETTY_PRINT)."\n");

        $zipPath = $workDir.'/export.zip';
        $zip = new ZipStreamWriter($zipPath, now()->utc());
        $zip->addFile('manifest.json', $workDir.'/manifest.json', deflate: true);

        foreach ($sections as $section) {
            $zip->addFile($section['file'], $workDir.'/'.$section['file'], deflate: true);
        }

        // Stored files the records refer to (attachments, CORE-008), streamed from their disk once
        // and stored as they are.
        foreach ($withFiles as $section) {
            foreach ($section->files($context) as $entry => $file) {
                if (preg_match('#^files/[0-9a-f-]{36}$#', $entry) !== 1) {
                    throw new LogicException('Invalid export file entry name.');
                }

                try {
                    $zip->addStream($entry, $file['stream'], deflate: false, size: $file['size']);
                } finally {
                    fclose($file['stream']);
                }
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $records
     */
    private function writeJsonArray(string $path, iterable $records): int
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write an export section.');
        }

        try {
            $count = 0;

            foreach ($records as $record) {
                $this->write($handle, ($count === 0 ? "[\n" : ",\n").json_encode($record, self::JSON_FLAGS));
                $count++;
            }

            $this->write($handle, $count === 0 ? "[]\n" : "\n]\n");

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write an export section.');
        }
    }

    private function put(string $path, string $data): void
    {
        if (file_put_contents($path, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write the export manifest.');
        }
    }

    private function mkdir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create the export working directory.');
        }
    }
}
