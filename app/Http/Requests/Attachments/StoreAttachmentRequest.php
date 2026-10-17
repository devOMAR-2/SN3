<?php

namespace App\Http\Requests\Attachments;

use App\Domain\Attachments\AttachmentFilename;
use App\Domain\Attachments\AttachmentQuota;
use App\Domain\Attachments\AttachmentTypes;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST /api/v1/tasks/{task}/attachments (API_SPEC.md §6.3 "Multipart upload — Project writer";
 * CORE-008, ADR-027 §5): `multipart/form-data` with exactly one file in the field `file`.
 *
 * In this order:
 *
 * 1. **403** for a caller who sees the task but may not write in its project, whatever was sent
 *    (AUTH-04).
 * 2. **413** `payload_too_large` for a file over `sn3.attachments.max_bytes` (25 MiB on Cloud; LIM-01),
 *    measured on the received file, never on the client's claim; also when PHP itself refused the
 *    upload for its size.
 * 3. **422**: `file` missing or not a single file (`required`, `file`), empty (`min`), or not an
 *    allowed type for its name (`mimetypes`, AttachmentTypes: the type is sniffed from the content;
 *    the client's Content-Type is ignored). Any other field is `unknown_field`.
 *
 * The file name is sanitised (AttachmentFilename) and kept as metadata only.
 */
class StoreAttachmentRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    /** @var list<string> */
    public const FIELDS = ['file'];

    private ?string $sniffed = null;

    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && ($this->user()?->can('contribute', $task->project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...$this->rejectUnknownFields(),
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->allFiles()), self::FIELDS) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'unknown_field');
                }

                $file = $this->file('file');

                if ($validator->errors()->has('file') || ! $file instanceof UploadedFile) {
                    return;
                }

                $path = $file->getRealPath();

                if ($path === false || (int) $file->getSize() === 0) {
                    $validator->errors()->add('file', 'min');

                    return;
                }

                $this->sniffed = AttachmentTypes::sniff($path);

                if (! AttachmentTypes::allows($this->sniffed, $this->filename())) {
                    $validator->errors()->add('file', 'mimetypes');
                }
            },
        ];
    }

    /** The received file's local path. */
    public function path(): string
    {
        $file = $this->file('file');
        $path = $file instanceof UploadedFile ? $file->getRealPath() : false;

        if ($path === false) {
            throw new LogicException('No validated upload.');
        }

        return $path;
    }

    /** The sanitised original file name. */
    public function filename(): string
    {
        $file = $this->file('file');

        return AttachmentFilename::sanitize($file instanceof UploadedFile ? $file->getClientOriginalName() : '');
    }

    /** The type sniffed from the content (validated against the allowlist). */
    public function mimeType(): string
    {
        return $this->sniffed ?? throw new LogicException('No validated upload.');
    }

    protected function getValidatorInstance(): ValidatorContract
    {
        // After authorization (403 first), before validation: an oversized file is 413 whatever else
        // is wrong with the request.
        $file = $this->file('file');

        if ($file instanceof UploadedFile) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new HttpException(413, 'The upload is too large.');
            }

            if ($file->isValid() && AttachmentQuota::exceedsFileLimit((int) $file->getSize())) {
                throw new HttpException(413, 'The upload is too large.');
            }
        }

        return parent::getValidatorInstance();
    }
}
