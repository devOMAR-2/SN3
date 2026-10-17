<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attachments\Actions\DeleteAttachment;
use App\Domain\Attachments\AttachmentFilename;
use App\Domain\Attachments\AttachmentStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\DestroyAttachmentRequest;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * One attachment (API_SPEC.md §6.3; CORE-008, ADR-027 §5): download and delete.
 *
 * `{attachment}` resolves through AccessibleAttachments on every request, so access is re-checked at
 * download time and a revoked member, a hidden project or a guessed ID gets the 404 of a missing file
 * (AUTH-05, DB-T08). Files are streamed through this route; there is no public or signed storage URL.
 * A visible attachment whose file cannot be read is a 503 `service_unavailable`, logged as an error.
 * Every download is `Content-Disposition: attachment` with the sanitised name, `nosniff`, a sandboxing
 * CSP and `no-store`, so a browser never renders or runs a file inline, polyglots included.
 */
class AttachmentController extends Controller
{
    public function download(Request $request, TaskAttachment $attachment, AttachmentStorage $storage): StreamedResponse
    {
        Gate::authorize('view', $attachment->task->project);

        try {
            $stream = $storage->diskOf($attachment)->readStream($attachment->storage_key);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            // The row is there and visible, its file is not (or the storage is down): an integrity
            // error for the operator, a temporary 503 for the client, never a disclosure.
            Log::error('attachment.file_missing', ['attachment_id' => $attachment->id, 'team_id' => $attachment->team_id, 'disk' => $attachment->storage_disk]);

            throw new HttpException(503);
        }

        $name = $attachment->original_name;

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) $attachment->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, AttachmentFilename::asciiFallback($name)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroy(DestroyAttachmentRequest $request, TaskAttachment $attachment, DeleteAttachment $delete): Response
    {
        /** @var User $user */
        $user = $request->user();

        $delete->handle($attachment, $user);

        return response()->noContent();
    }
}
