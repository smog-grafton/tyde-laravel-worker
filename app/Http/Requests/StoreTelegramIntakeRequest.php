<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTelegramIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intake_mode' => ['nullable', 'string', 'in:server_path,remote_url'],
            'source_disk' => ['nullable', 'string', 'max:100'],
            'source_path' => ['nullable', 'string', 'max:500'],
            'source_url' => ['nullable', 'url', 'starts_with:http://,https://', 'max:2048'],
            'file' => ['nullable', 'file'],
            'title' => ['nullable', 'string', 'max:255'],
            'original_filename' => ['nullable', 'string', 'max:255'],
            'episode' => ['nullable', 'integer', 'min:1', 'max:999'],
            'vj' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'telegram_message_id' => ['nullable', 'string', 'max:100'],
            'telegram_channel' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable'],
            'queue_outputs' => ['nullable', 'boolean'],
            'presets' => ['nullable', 'array'],
            'presets.*' => ['integer', 'exists:transcode_presets,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (!$this->hasFile('file') && !$this->filled('source_path') && !$this->filled('source_url')) {
                    $validator->errors()->add('source_path', 'Provide a source_path, source_url, or upload a file.');
                }
            },
        ];
    }
}
