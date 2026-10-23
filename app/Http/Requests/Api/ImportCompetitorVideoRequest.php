<?php

namespace App\Http\Requests\Api;

use App\Models\CompetitorVideoWatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 桥接程序导入一条竞品视频及其评论。
 */
class ImportCompetitorVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $comments = $this->input('comments');
        if (is_string($comments) && $comments !== '') {
            $decoded = json_decode($comments, true);
            if (is_array($decoded)) {
                $this->merge(['comments' => $decoded]);
            }
        }

        if ($this->filled('watch_id') && is_string($this->input('watch_id'))) {
            $this->merge(['watch_id' => (int) $this->input('watch_id')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(CompetitorVideoWatch::PLATFORMS)],
            'external_id' => ['nullable', 'string', 'max:120'],
            'source_url' => ['required', 'url', 'max:2000'],
            'author' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:500'],
            'caption' => ['nullable', 'string', 'max:10000'],
            'collector' => ['nullable', Rule::in(CompetitorVideoWatch::COLLECTORS)],
            'watch_id' => ['nullable', 'integer', 'exists:competitor_video_watches,id'],
            'comments' => ['nullable', 'array', 'max:'.(int) config('competitor_videos.raw_comment_limit')],
            'comments.*.id' => ['required_with:comments', 'string', 'max:120'],
            'comments.*.parent_id' => ['nullable', 'string', 'max:120'],
            'comments.*.author' => ['nullable', 'string', 'max:200'],
            'comments.*.body' => ['required_with:comments', 'string', 'max:5000'],
            'comments.*.published_at' => ['nullable', 'date'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.(int) ((int) config('competitor_videos.max_file_bytes') / 1024)],
        ];
    }
}
