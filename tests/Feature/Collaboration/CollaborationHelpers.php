<?php

// Shared helpers of the CORE-008 collaboration tests (required by each file; not a test file itself).

require_once __DIR__.'/../Tasks/TaskHelpers.php';

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * File contents for upload tests, keyed by kind.
 */
function collabFixture(string $kind): string
{
    return match ($kind) {
        'png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true),
        'pdf' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        'text' => "Meeting notes\nملاحظات الاجتماع\n",
        'exe' => 'MZ'.str_repeat("\0", 58).pack('V', 64)."PE\0\0".str_repeat("\0", 200),
        'html' => '<!DOCTYPE html><html><body><script>alert(document.cookie)</script></body></html>',
        'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        'php' => "<?php system(\$_GET['c']);\n",
        // A valid GIF that is also a script payload (GIFAR-style polyglot).
        'gif-polyglot' => "GIF89a\x01\x00\x01\x00\x00\x00\x00;<script>alert(1)</script>",
        // A PDF with a zip archive appended (PDF/ZIP polyglot).
        'pdf-zip' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".collabZip(),
        'zip' => collabZip(),
        'binary' => random_bytes(64),
        default => throw new InvalidArgumentException($kind),
    };
}

function collabZip(): string
{
    $path = tempnam(sys_get_temp_dir(), 'sn3-zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('run.exe', 'MZ payload');
    $zip->close();
    $content = (string) file_get_contents($path);
    unlink($path);

    return $content;
}

/**
 * A real uploaded file (test mode), whose size and content are what the server reads.
 */
function collabUpload(string $content, string $name, ?string $clientType = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sn3-upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $clientType, null, true);
}

/**
 * @param  array<string, mixed>  $extra  more form fields or files
 */
function collabUploadTo(User $user, Task $task, UploadedFile|array|null $file, ?string $key = null, array $extra = []): TestResponse
{
    $data = $file === null ? $extra : ['file' => $file, ...$extra];

    return test()->actingAs($user)->post("/api/v1/tasks/{$task->id}/attachments", $data, [
        'Accept' => 'application/json',
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
    ]);
}

/**
 * Every stored attachment file on the (faked) attachments disk.
 *
 * @return list<string>
 */
function collabStoredFiles(): array
{
    return array_values(Storage::disk('attachments')->allFiles());
}
