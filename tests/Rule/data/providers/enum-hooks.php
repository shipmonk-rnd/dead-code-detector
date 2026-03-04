<?php declare(strict_types = 1);

namespace EnumProviderHooks;

enum Status: string {
    case Active = 'active';
    case Inactive = 'inactive'; // error: Unused EnumProviderHooks\Status::Inactive
}

class Project
{
    public Status $status {
        get => Status::from('active');
    }
}

function test(): void {
    echo (new Project())->status;
}
