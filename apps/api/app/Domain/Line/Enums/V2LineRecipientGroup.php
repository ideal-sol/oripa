<?php

namespace App\Domain\Line\Enums;

enum V2LineRecipientGroup: string
{
    case LinkedMembers = 'linked_members';
    case UnlinkedFriends = 'unlinked_friends';
    case AllFriends = 'all_friends';
}
