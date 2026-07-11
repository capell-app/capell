<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum UserSchemaHookEnum: string
{
    case BeforeIdentity = 'before_identity';
    case AfterIdentity = 'after_identity';
    case BeforeCredentials = 'before_credentials';
    case AfterCredentials = 'after_credentials';
    case BeforeRoles = 'before_roles';
    case AfterRoles = 'after_roles';
    case BeforeProfile = 'before_profile';
    case AfterProfile = 'after_profile';
    case Footer = 'footer';
}
