<?php

namespace App\Data;

use App\Models\RelationshipInvitation;

final readonly class CreatedRelationshipInvitation
{
    public function __construct(
        public RelationshipInvitation $invitation,
        public string $token,
    ) {}
}
