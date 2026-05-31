<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bank;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BankPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bank');
    }

    public function view(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('View:Bank');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bank');
    }

    public function update(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('Update:Bank');
    }

    public function delete(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('Delete:Bank');
    }

    public function restore(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('Restore:Bank');
    }

    public function forceDelete(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('ForceDelete:Bank');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bank');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bank');
    }

    public function replicate(AuthUser $authUser, Bank $bank): bool
    {
        return $authUser->can('Replicate:Bank');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bank');
    }
}
