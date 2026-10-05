<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Package */
final class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'tests' => $this->whenLoaded('tests', fn () => $this->tests->map(fn (LabTest $test) => [
                'id' => $test->id,
                'code' => $test->code,
                'name' => $test->name,
            ])->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
