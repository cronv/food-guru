<?php

namespace App\Http\Resources;

use App\Models\JournalIssue;
use Illuminate\Http\Request;

/** @mixin JournalIssue */
class JournalIssueResource extends JournalIssueCardResource
{
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'moderation_note' => $this->when($isStaff, $this->moderation_note),
            // Исходное имя файла нужно редактору, чтобы понять, что он загрузил.
            'file_name' => $this->when($isStaff, $this->file_name),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
        ]);
    }
}
