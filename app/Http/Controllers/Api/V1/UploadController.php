<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class UploadController extends Controller
{
    private function saveFile($file)
    {
        $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $dir = public_path('uploads');
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
        $file->move($dir, $name);
        return asset('uploads/' . $name);
    }

    public function image(Request $request)
    {
        $request->validate(['file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240']);
        $url = $this->saveFile($request->file('file'));
        return response()->json([
            'data' => ['url' => $url],
            'message' => 'Imagen subida exitosamente.',
        ]);
    }

    public function video(Request $request)
    {
        $request->validate(['file' => 'required|mimes:mp4,mov,avi,wmv,webm,mkv|max:204800']);
        $url = $this->saveFile($request->file('file'));
        return response()->json([
            'data' => ['url' => $url],
            'message' => 'Video subido exitosamente.',
        ]);
    }

    public function multiple(Request $request)
    {
        $request->validate(['files' => 'required|array', 'files.*' => 'file|max:102400']);
        $urls = [];
        foreach ($request->file('files') as $file) {
            $urls[] = $this->saveFile($file);
        }
        return response()->json(['data' => ['urls' => $urls], 'message' => count($urls) . ' archivos subidos.']);
    }
}
