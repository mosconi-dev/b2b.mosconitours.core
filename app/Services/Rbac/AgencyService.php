<?php

namespace App\Services\Rbac;

use App\Enums\AgencyType;
use App\Exceptions\RbacException;
use App\Models\Agency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Agency lifecycle. Sits beside RoleService/UserAdminService because an agency is
 * the scope those two operate within, and it shares their audit seam.
 *
 * It deliberately holds no authorization logic: an agency's type and its place in
 * the parent tree never grant or withhold anything. Access comes from the roles a
 * user holds, and nothing here reads or writes permissions.
 */
class AgencyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Where logos live on the `public` disk. */
    private const LOGO_DIR = 'agency-logos';

    /**
     * @param  array{name: string, type: string, code?: string|null, parent_id?: int|null, contact_email?: string|null, contact_phone?: string|null, address?: string|null}  $data
     */
    public function create(array $data, ?UploadedFile $logo = null): Agency
    {
        $agency = new Agency;
        $agency->code = $this->uniqueCode($data['code'] ?? null, $data['name']);
        $agency->name = $data['name'];
        $agency->type = AgencyType::from($data['type']);
        $agency->parent_id = $data['parent_id'] ?? null;
        $agency->contact_email = $data['contact_email'] ?? null;
        $agency->contact_phone = $data['contact_phone'] ?? null;
        $agency->address = $data['address'] ?? null;
        $agency->logo_path = $logo ? $this->storeLogo($logo) : null;
        $agency->is_active = true;
        $agency->save();

        $this->audit->log('agency.created', $agency, ['type' => $agency->type->value]);

        return $agency;
    }

    /**
     * The code is immutable once issued — bookings, reports and exports reference
     * it, so renaming is limited to the display name.
     *
     * @param  array{name: string, type: string, parent_id?: int|null}  $data
     */
    public function update(Agency $agency, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): Agency
    {
        $this->guardParent($agency, $data['parent_id'] ?? null);

        $agency->name = $data['name'];
        $agency->type = AgencyType::from($data['type']);
        $agency->parent_id = $data['parent_id'] ?? null;
        $agency->contact_email = $data['contact_email'] ?? null;
        $agency->contact_phone = $data['contact_phone'] ?? null;
        $agency->address = $data['address'] ?? null;

        // A new upload wins over the remove tick; either way the old file goes.
        if ($logo !== null) {
            $this->deleteLogo($agency);
            $agency->logo_path = $this->storeLogo($logo);
        } elseif ($removeLogo) {
            $this->deleteLogo($agency);
            $agency->logo_path = null;
        }

        $agency->save();

        $this->audit->log('agency.updated', $agency, ['type' => $agency->type->value]);

        return $agency;
    }

    /**
     * Store under a random name — the original filename is attacker-controlled and
     * never touches the filesystem. The extension is taken from the validated MIME
     * type, so it always matches the real content.
     */
    private function storeLogo(UploadedFile $logo): string
    {
        return $logo->storeAs(
            self::LOGO_DIR,
            Str::random(40).'.'.$logo->extension(),
            'public',
        );
    }

    private function deleteLogo(Agency $agency): void
    {
        if ($agency->logo_path) {
            Storage::disk('public')->delete($agency->logo_path);
        }
    }

    public function toggleActive(Agency $agency): Agency
    {
        $agency->is_active = ! $agency->is_active;
        $agency->save();

        $this->audit->log($agency->is_active ? 'agency.activated' : 'agency.deactivated', $agency);

        return $agency;
    }

    /**
     * Soft delete. Refused while anything still points at the agency, so members
     * are never left referencing a deleted scope and outlets are never orphaned.
     */
    /**
     * Soft delete keeps history, so the logo file is kept too — deleting it would
     * blank out the archived record. It is removed only on an explicit replace/clear.
     */
    public function delete(Agency $agency): void
    {
        if (($members = $agency->users()->count()) > 0) {
            throw new RbacException("This agency still has {$members} user(s). Move or delete them first.");
        }

        if ($agency->children()->exists()) {
            throw new RbacException('This agency still has agencies reporting to it. Reassign them first.');
        }

        $agency->delete(); // soft delete — preserves history
        $this->audit->log('agency.deleted', $agency);
    }

    /**
     * An agency cannot report to itself or to one of its own descendants.
     */
    private function guardParent(Agency $agency, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $seen = [];
        $cursor = $parentId;

        while ($cursor !== null) {
            if ($cursor === $agency->id) {
                throw new RbacException('An agency cannot report to itself or to one of its own outlets.');
            }

            if (isset($seen[$cursor])) {
                return; // pre-existing cycle in the data — not this change's doing
            }

            $seen[$cursor] = true;
            $cursor = Agency::whereKey($cursor)->value('parent_id');
        }
    }

    private function uniqueCode(?string $requested, string $name): string
    {
        $base = Str::slug($requested ?: $name) ?: 'agency';
        $base = Str::limit($base, 28, '');
        $code = $base;
        $i = 2;

        while (Agency::withTrashed()->where('code', $code)->exists()) {
            $code = "{$base}-{$i}";
            $i++;
        }

        return $code;
    }
}
