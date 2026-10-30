<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ActivityLog;
use App\Models\ClubItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Материалы закрытой академии. Видео — на закрытом диске local, не в public. */
class AdminClubController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ClubItem::with('cover')->orderByDesc('updated_at')->get()->map(fn ($i) => $this->row($i))]);
    }

    public function show(ClubItem $item): JsonResponse
    {
        return response()->json(['data' => $this->row($item->load('cover')) + ['body' => $item->body, 'data' => $item->data]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $item = ClubItem::create($data + ['slug' => $data['slug'] ?? $this->slug($data['title'])]);
        ActivityLog::record($request->user(), 'club.created', $item, ['title' => $item->title]);

        return $this->show($item);
    }

    public function update(Request $request, ClubItem $item): JsonResponse
    {
        $data = $this->validated($request, $item);
        if (($data['is_published'] ?? false) && ! $item->published_at) {
            $data['published_at'] = now();
        }
        $item->update($data);
        ActivityLog::record($request->user(), 'club.updated', $item, ['title' => $item->title]);

        return $this->show($item->fresh());
    }

    public function uploadVideo(Request $request, ClubItem $item): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/webm', 'max:512000'],
        ], ['file.mimetypes' => 'Подойдёт MP4 или WebM.', 'file.max' => 'Видео больше 500 МБ — сожмите его.']);

        $old = $item->video_path;
        $path = $request->file('file')->store('club', 'local');
        $item->forceFill(['video_disk' => 'local', 'video_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        ActivityLog::record($request->user(), 'club.video_uploaded', $item, ['title' => $item->title]);

        return $this->show($item);
    }

    private function validated(Request $request, ?ClubItem $item = null): array
    {
        $creating = $item === null;

        return $request->validate([
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(ClubItem::KINDS))],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('club_items', 'slug')->ignore($item?->id)],
            'lead' => ['sometimes', 'nullable', 'string', 'max:400'],
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'chef_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'data' => ['sometimes', 'nullable', 'array'],
            'data.ingredients' => ['sometimes', 'array', 'max:100'],
            'data.ingredients.*' => ['string', 'max:200'],
            'data.steps' => ['sometimes', 'array', 'max:60'],
            'data.steps.*' => ['string', 'max:4000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    private function row(ClubItem $i): array
    {
        return $i->toCard(true) + ['id' => $i->id, 'is_published' => $i->is_published, 'cover_media_id' => $i->cover_media_id];
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'material';
        $slug = $base;
        for ($n = 2; ClubItem::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
