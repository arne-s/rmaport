<?php

return [

    'resources' => [
        'PermissionResource' => \App\Filament\Resources\PermissionResource::class,
        'RoleResource' => \App\Filament\Resources\RoleResource::class,
    ],

    'preload_roles' => false,

    'preload_permissions' => true,

    'navigation_section_group' => 'filament-spatie-roles-permissions::filament-spatie.section.roles_and_permissions',

    'team_model' => null,

    'scope_to_tenant' => false,

    'scope_roles_to_tenant' => false,

    'scope_premissions_to_tenant' => false,

    'super_admin_role_name' => 'Super Admin',

    'should_register_on_navigation' => [
        'permissions' => true,
        'roles' => true,
    ],

    'should_show_permissions_for_roles' => true,

    'should_use_simple_modal_resource' => [
        'permissions' => false,
        'roles' => false,
    ],

    'should_remove_empty_state_actions' => [
        'permissions' => false,
        'roles' => false,
    ],

    'should_redirect_to_index' => [
        'permissions' => [
            'after_create' => false,
            'after_edit' => false,
        ],
        'roles' => [
            'after_create' => false,
            'after_edit' => false,
        ],
    ],

    'should_display_relation_managers' => [
        'permissions' => true,
        'users' => true,
        'roles' => true,
    ],

    'clusters' => [
        'permissions' => null,
        'roles' => null,
    ],

    'guard_names' => [
        'web' => 'web',
    ],

    'toggleable_guard_names' => [
        'roles' => [
            'isToggledHiddenByDefault' => true,
        ],
        'permissions' => [
            'isToggledHiddenByDefault' => true,
        ],
    ],

    'default_guard_name' => 'web',

    'should_show_guard' => false,

    'model_filter_key' => 'return \'%\'.$value;',

    'user_name_column' => 'name',

    'user_name_searchable_columns' => ['first_name', 'last_name', 'email'],

    'icons' => [
        'role_navigation' => 'heroicon-o-lock-closed',
        'permission_navigation' => 'heroicon-o-lock-closed',
    ],

    'sort' => [
        'role_navigation' => false,
        'permission_navigation' => false,
    ],

    'generator' => [

        'guard_names' => [
            'web',
        ],

        'permission_affixes' => [
            'viewAnyPermission' => 'view-any',
            'viewPermission' => 'view',
            'createPermission' => 'create',
            'updatePermission' => 'update',
            'deletePermission' => 'delete',
            'restorePermission' => 'restore',
            'forceDeletePermission' => 'force-delete',
            'replicate',
            'reorder',
        ],

        'permission_name' => 'return $permissionAffix . \' \' . $modelName;',

        'discover_models_through_filament_resources' => false,

        'model_directories' => [
            app_path('Models'),
        ],

        'custom_models' => [
            //
        ],

        'excluded_models' => [
            //
        ],

        'excluded_policy_models' => [
            \App\Models\User::class,
        ],

        'custom_permissions' => [],

        'user_model' => \App\Models\User::class,

        'user_model_class' => 'User',

        'policies_namespace' => 'App\Policies',
    ],
];
