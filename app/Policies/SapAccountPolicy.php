<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SapAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SapAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SapAccount');
    }

    public function view(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('View:SapAccount');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SapAccount');
    }

    public function update(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('Update:SapAccount');
    }

    public function delete(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('Delete:SapAccount');
    }

    public function restore(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('Restore:SapAccount');
    }

    public function forceDelete(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('ForceDelete:SapAccount');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SapAccount');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SapAccount');
    }

    public function replicate(AuthUser $authUser, SapAccount $sapAccount): bool
    {
        return $authUser->can('Replicate:SapAccount');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SapAccount');
    }
}
