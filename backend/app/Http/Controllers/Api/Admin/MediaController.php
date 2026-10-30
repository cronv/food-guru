<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MediaController
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Media::query()->latest('id');

        // Контент-менеджер видит только свои загрузки.
        if (! $request->user()->atLeast(UserRole::EditorLead)) {
            $query->where('uploaded_by', $request->user()->id);
        }

        return MediaResource::collection($query->paginate(24));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            'alt' => ['nullable', 'string', 'max:200'],
        ], [
            'file.max' => 'Файл должен быть меньше 8 МБ.',
            'file.mimes' => 'Подойдут JPEG, PNG или WebP.',
        ]);

        $media = $this->media->store($request->file('file'), $request->user(), $data['alt'] ?? null);

        return response()->json(['data' => new MediaResource($media)], 201);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        abort_unless(
            $media->uploaded_by === $request->user()->id
                || $request->user()->atLeast(UserRole::EditorLead),
            403
        );

        $this->media->delete($media);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
