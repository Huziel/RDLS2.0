<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomPage;
use Illuminate\Http\Request;

class CustomPageController extends Controller
{
    private function requireSuperAdmin(Request $request)
    {
        if (!$request->user()?->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }
    }

    public function index(Request $request)
    {
        $this->requireSuperAdmin($request);
        $pages = CustomPage::orderBy('title')->get();
        return response()->json(['data' => $pages]);
    }

    public function store(Request $request)
    {
        $this->requireSuperAdmin($request);
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:custom_pages,slug', 'regex:/^[a-z0-9\-]+$/'],
            'content' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);

        $page = CustomPage::create($request->only(['title', 'slug', 'content', 'active']));
        return response()->json(['data' => $page, 'message' => 'Pagina creada.'], 201);
    }

    public function update(Request $request, $id)
    {
        $this->requireSuperAdmin($request);
        $page = CustomPage::findOrFail($id);

        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:custom_pages,slug,' . $id, 'regex:/^[a-z0-9\-]+$/'],
            'content' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);

        $page->update($request->only(['title', 'slug', 'content', 'active']));
        return response()->json(['data' => $page, 'message' => 'Pagina actualizada.']);
    }

    public function destroy(Request $request, $id)
    {
        $this->requireSuperAdmin($request);
        CustomPage::findOrFail($id)->delete();
        return response()->json(['message' => 'Pagina eliminada.']);
    }

    public function showBySlug($slug)
    {
        $page = CustomPage::active()->where('slug', $slug)->firstOrFail();
        return response()->json(['data' => $page]);
    }
}
