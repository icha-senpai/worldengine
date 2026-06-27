<?php

namespace App\Http\Controllers\Connections;

use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Http\Controllers\ManagedResourceController;

class GroupRelationshipMembershipController extends ManagedResourceController
{
    protected string $resource = 'group-relationship-memberships';

    protected string $modelClass = GroupRelationshipEntity::class;
}
