<?php

namespace Whilesmart\AgentProfiles\Enums;

enum VersionStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Revoked = 'revoked';
}
