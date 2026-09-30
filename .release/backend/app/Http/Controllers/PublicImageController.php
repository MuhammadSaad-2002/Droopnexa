<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicImageController extends Controller
{
    public function __invoke(string $directory, string $filename): BinaryFileResponse
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/i', $filename), 404);
        $path = $directory.'/'.$filename;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
