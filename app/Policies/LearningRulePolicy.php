<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LearningRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LearningRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LearningRule');
    }

    public function view(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('View:LearningRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LearningRule');
    }

    public function update(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('Update:LearningRule');
    }

    public function delete(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('Delete:LearningRule');
    }

    public function restore(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('Restore:LearningRule');
    }

    public function forceDelete(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('ForceDelete:LearningRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LearningRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LearningRule');
    }

    public function replicate(AuthUser $authUser, LearningRule $learningRule): bool
    {
        return $authUser->can('Replicate:LearningRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LearningRule');
    }
}
