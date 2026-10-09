<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\NoteAttachment;
use Illuminate\Support\Facades\Storage;

class NoteAttachmentController extends Controller
{
    
public function download(NoteAttachment $attachment)
    {
        $user = auth()->user();

        abort_unless(
            $attachment->user_id === $user->id,
            403
        );

        abort_unless(
            $user->notes()
                ->whereKey($attachment->note_id)
                ->exists(),
            404
        );

        abort_unless(
            Storage::disk('local')->exists($attachment->file_path),
            404
        );

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name
        );
    }
}
