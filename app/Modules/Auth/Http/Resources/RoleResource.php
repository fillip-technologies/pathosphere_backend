<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Permissions\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope_level' => $this->scope_level,
            'is_system' => $this->is_system,
            'description' => $this->description,
            'requires_mfa' => $this->whenLoaded('permissionEntries', fn () => $this->requiresMfa()),
            'permissions' => $this->whenLoaded('permissionEntries', fn () => array_map(
                fn (Permission $permission) => $permission->value,
                $this->permissions(),
            )),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
