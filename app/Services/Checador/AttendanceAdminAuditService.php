<?php

namespace App\Services\Checador;

use Illuminate\Support\Arr;

class AttendanceAdminAuditService
{
    public function append(
        ?array $metadata,
        string $action,
        ?object $adminUser,
        string $reason,
        ?array $original,
        array $new
    ): array {
        $metadata = $metadata ?? [];

        $history = Arr::get($metadata, 'admin_audit_history', []);

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = [
            'source' => 'comunicacion360_admin',
            'action' => $action,
            'admin_user_id' => $adminUser?->id,
            'admin_user_name' => $adminUser?->name ?? $adminUser?->nombre ?? null,
            'changed_at' => now()->toDateTimeString(),
            'reason' => $reason,
            'original' => $original,
            'new' => $new,
        ];

        $metadata['admin_audit_history'] = $history;

        return $metadata;
    }
}