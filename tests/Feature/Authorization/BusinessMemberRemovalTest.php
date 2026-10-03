<?php



namespace Tests\Feature\Authorization;



use App\Domains\Identity\Services\RoleService;

use App\Domains\Organization\Models\Business;

use App\Domains\Organization\Models\BusinessUser;

use App\Domains\Identity\Support\PermissionCatalog;

use Spatie\Permission\Models\Permission;

use App\Models\User;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;



class BusinessMemberRemovalTest extends TestCase

{

    use RefreshDatabase;



    protected Business $business;



    protected User $owner;



    protected RoleService $roleService;



    protected function setUp(): void

    {

        parent::setUp();



        $this->roleService = app(RoleService::class);



        $this->business = Business::factory()->create();



        $this->createPermissions();



        $this->roleService->provisionBusinessRoles(

            $this->business->id

        );



        $this->owner = User::factory()->create();



        BusinessUser::create([

            'business_id' => $this->business->id,

            'user_id' => $this->owner->id,

            'status' => 'active',

            'joined_at' => now(),

        ]);



        $this->roleService->assignOwner(
            $this->owner,
            $this->business->id
        );

    }



    protected function createMember(

        string $roleName = 'Manager'

    ): User {

        $user = User::factory()->create();



        BusinessUser::create([

            'business_id' => $this->business->id,

            'user_id' => $user->id,

            'status' => 'active',

            'joined_at' => now(),

        ]);



        $this->roleService->assignRole(

            $user,

            $roleName,

            $this->business->id

        );



        return $user;

    }



    protected function currentBusinessSession(): array

    {

        return [

            'current_business_id' => $this->business->id,

        ];

    }



    protected function createPermissions(): void

    {

        foreach (PermissionCatalog::all() as $permission) {

            Permission::firstOrCreate([

                'name' => $permission,

                'guard_name' => 'web',

            ]);

        }

    }



    public function test_owner_can_remove_employee(): void

    {

        $employee = $this->createMember('Cashier');



        $response = $this

            ->actingAs($this->owner)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$employee->id}"

            );



        $response

            ->assertOk()

            ->assertJson([

                'success' => true,

                'message' => 'Employee removed successfully.',

            ]);



        $this->assertDatabaseHas('business_user', [

            'business_id' => $this->business->id,

            'user_id' => $employee->id,

            'status' => 'inactive',

        ]);



        $this->assertDatabaseMissing(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $employee->id,

                'model_type' => $employee->getMorphClass(),

                'team_id' => $this->business->id,

            ]

        );



        $this->assertDatabaseHas('users', [

            'id' => $employee->id,

        ]);

    }



    public function test_manager_can_remove_employee(): void

    {

        $manager = $this->createMember('Manager');



        $employee = $this->createMember('Cashier');



        $response = $this

            ->actingAs($manager)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$employee->id}"

            );



        $response

            ->assertOk()

            ->assertJson([

                'success' => true,

                'message' => 'Employee removed successfully.',

            ]);



        $this->assertDatabaseHas('business_user', [

            'business_id' => $this->business->id,

            'user_id' => $employee->id,

            'status' => 'inactive',

        ]);



        $this->assertDatabaseMissing(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $employee->id,

                'model_type' => $employee->getMorphClass(),

                'team_id' => $this->business->id,

            ]

        );



        $this->assertDatabaseHas('users', [

            'id' => $employee->id,

        ]);

    }



    public function test_user_without_remove_permission_cannot_remove_employee(): void

    {

        $cashier = $this->createMember('Cashier');



        $employee = $this->createMember('Cashier');



        $response = $this

            ->actingAs($cashier)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$employee->id}"

            );



        $response->assertForbidden();



        $this->assertDatabaseHas('business_user', [

            'business_id' => $this->business->id,

            'user_id' => $employee->id,

            'status' => 'active',

        ]);



        $this->assertDatabaseHas(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $employee->id,

                'model_type' => $employee->getMorphClass(),

                'team_id' => $this->business->id,

            ]

        );

    }



    public function test_user_cannot_remove_themselves(): void

    {

        $response = $this

            ->actingAs($this->owner)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$this->owner->id}"

            );



        $response->assertStatus(422);



        $response->assertJsonValidationErrors([

            'member',

        ]);



        $this->assertDatabaseHas('business_user', [

            'business_id' => $this->business->id,

            'user_id' => $this->owner->id,

            'status' => 'active',

        ]);



        $this->assertDatabaseHas(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $this->owner->id,

                'model_type' => $this->owner->getMorphClass(),

                'team_id' => $this->business->id,

            ]

        );

    }



    public function test_owner_cannot_be_removed(): void

    {

        $manager = $this->createMember('Manager');



        $response = $this

            ->actingAs($manager)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$this->owner->id}"

            );



        $response->assertStatus(422);



        $response->assertJsonValidationErrors([

            'member',

        ]);



        $this->assertDatabaseHas('business_user', [

            'business_id' => $this->business->id,

            'user_id' => $this->owner->id,

            'status' => 'active',

        ]);



        $this->assertDatabaseHas(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $this->owner->id,

                'model_type' => $this->owner->getMorphClass(),

                'team_id' => $this->business->id,

            ]

        );

    }



    public function test_member_from_another_business_cannot_be_removed(): void

    {

        $businessB = Business::factory()->create();



        $this->roleService->provisionBusinessRoles(

            $businessB->id

        );



        $employeeFromBusinessB = User::factory()->create();



        BusinessUser::create([

            'business_id' => $businessB->id,

            'user_id' => $employeeFromBusinessB->id,

            'status' => 'active',

            'joined_at' => now(),

        ]);



        $this->roleService->assignRole(

            $employeeFromBusinessB,

            'Cashier',

            $businessB->id

        );



        $response = $this

            ->actingAs($this->owner)

            ->withSession($this->currentBusinessSession())

            ->deleteJson(

                "/api/businesses/current/members/{$employeeFromBusinessB->id}"

            );



        $response->assertNotFound();



        $this->assertDatabaseHas('business_user', [

            'business_id' => $businessB->id,

            'user_id' => $employeeFromBusinessB->id,

            'status' => 'active',

        ]);



        $this->assertDatabaseHas(

            config('permission.table_names.model_has_roles'),

            [

                'model_id' => $employeeFromBusinessB->id,

                'model_type' => $employeeFromBusinessB->getMorphClass(),

                'team_id' => $businessB->id,

            ]

        );

    }

}
