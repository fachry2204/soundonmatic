<?php

namespace Tests\Feature;

use App\Livewire\Automation\Dashboard;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_viewer_can_view_dashboard_but_not_manage_pages(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->get('/mappings')->assertForbidden();
        $this->get('/sessions')->assertForbidden();
    }

    public function test_viewer_cannot_start_automation(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Livewire::test(Dashboard::class)->call('start')->assertForbidden();
    }

    public function test_admin_has_all_automation_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->assertTrue($admin->can('automation.run'));
        $this->assertTrue($admin->can('automation.retry'));
        $this->assertTrue($admin->can('mappings.manage'));
        $this->assertTrue($admin->can('sessions.manage'));
    }

    public function test_existing_roles_receive_release_status_access_without_losing_other_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $role = $admin->roles->first();
        $role->revokePermissionTo('release-status.view');

        $migration = require database_path('migrations/2026_11_24_000001_grant_release_status_permission.php');
        $migration->up();

        $this->actingAs($admin->fresh())->get('/release-status')->assertOk();
        $this->assertTrue($admin->fresh()->can('automation.run'));
    }

    public function test_existing_admin_receives_user_management_permission(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->roles->first()->revokePermissionTo('users.manage');

        $migration = require database_path('migrations/2026_11_24_000002_grant_user_management_permission.php');
        $migration->up();

        $this->actingAs($admin->fresh())->get('/users')->assertOk();
        $this->assertTrue($admin->fresh()->can('automation.run'));
        $this->assertFalse(\Spatie\Permission\Models\Role::findByName('Viewer')->hasPermissionTo('users.manage'));
    }
}
