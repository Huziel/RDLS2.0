<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    private function saveFile($file, array $allowedMimes)
    {
        $mime = (string) $file->getMimeType();
        if (! isset($allowedMimes[$mime])) {
            abort(422, 'El contenido del archivo no esta permitido.');
        }
        $name = Str::uuid().'.'.$allowedMimes[$mime];
        $dir = public_path('uploads');
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $file->move($dir, $name);

        return asset('uploads/'.$name);
    }

    public function image(Request $request)
    {
        $request->validate(['file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240']);
        $url = $this->saveFile($request->file('file'), [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ]);

        return response()->json([
            'data' => ['url' => $url],
            'message' => 'Imagen subida exitosamente.',
        ]);
    }

    public function video(Request $request)
    {
        $request->validate(['file' => 'required|mimes:mp4,mov,avi,wmv,webm,mkv|max:204800']);
        $url = $this->saveFile($request->file('file'), [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
        ]);

        return response()->json([
            'data' => ['url' => $url],
            'message' => 'Video subido exitosamente.',
        ]);
    }

    public function multiple(Request $request)
    {
        return response()->json(['message' => 'La carga multiple esta deshabilitada por seguridad.'], 503);
    }
}
