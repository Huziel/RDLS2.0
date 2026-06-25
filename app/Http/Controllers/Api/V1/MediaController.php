<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaPhoto;
use App\Models\MediaVideo;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function videos(Request $r)
    {
        $perPage = $r->get('per_page', 20);

        if ($r->isMethod('post')) {
            $r->validate(['url' => 'required|string']);
            MediaVideo::create(['idLog' => $r->user()->id, 'urlVideo' => $r->url, 'fecha' => now()->format('Y-m-d H:i:s')]);
            return response()->json(['message' => 'Video guardado.'], 201);
        }

        $videos = MediaVideo::where('idLog', $r->user()->id)
            ->orderByDesc('id')->paginate($perPage);

        return response()->json($videos);
    }

    public function deleteVideo($id)
    {
        MediaVideo::where('idLog', request()->user()->id)->where('id', $id)->delete();
        return response()->json(['message' => 'Video eliminado.']);
    }

    public function photos(Request $r)
    {
        $perPage = $r->get('per_page', 20);

        if ($r->isMethod('post')) {
            $r->validate(['url' => 'required|string']);
            MediaPhoto::create(['idLog' => $r->user()->id, 'urlFoto' => $r->url, 'fecha' => now()->format('Y-m-d H:i:s')]);
            return response()->json(['message' => 'Foto guardada.'], 201);
        }

        $photos = MediaPhoto::where('idLog', $r->user()->id)
            ->orderByDesc('id')->paginate($perPage);

        return response()->json($photos);
    }

    public function deletePhoto($id)
    {
        MediaPhoto::where('idLog', request()->user()->id)->where('id', $id)->delete();
        return response()->json(['message' => 'Foto eliminada.']);
    }
}
